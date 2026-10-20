<?php

namespace Botble\Member\Providers;

use Botble\Api\Facades\ApiHelper;
use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Facades\EmailHandler;
use Botble\Base\Facades\PanelSectionManager;
use Botble\Base\Forms\FieldOptions\HiddenFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\Fields\HiddenField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\FormAbstract;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\PanelSections\PanelSectionItem;
use Botble\Base\Supports\DashboardMenuItem;
use Botble\Base\Supports\Language as BaseLanguage;
use Botble\Base\Supports\ServiceProvider;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\Blog\Forms\PostForm;
use Botble\Language\Facades\Language;
use Botble\LanguageAdvanced\Supports\LanguageAdvancedManager;
use Botble\Member\Forms\Fronts\Auth\ForgotPasswordForm;
use Botble\Member\Forms\Fronts\Auth\LoginForm;
use Botble\Member\Forms\Fronts\Auth\RegisterForm;
use Botble\Member\Forms\Fronts\Auth\ResetPasswordForm;
use Botble\Member\Http\Middleware\RedirectIfMember;
use Botble\Member\Http\Middleware\RedirectIfNotMember;
use Botble\Member\Http\Requests\Fronts\Auth\ForgotPasswordRequest;
use Botble\Member\Http\Requests\Fronts\Auth\LoginRequest;
use Botble\Member\Http\Requests\Fronts\Auth\RegisterRequest;
use Botble\Member\Http\Requests\Fronts\Auth\ResetPasswordRequest;
use Botble\Member\Models\Member;
use Botble\Member\Models\MemberActivityLog;
use Botble\Member\Repositories\Eloquent\MemberActivityLogRepository;
use Botble\Member\Repositories\Eloquent\MemberRepository;
use Botble\Member\Repositories\Interfaces\MemberActivityLogInterface;
use Botble\Member\Repositories\Interfaces\MemberInterface;
use Botble\Setting\PanelSections\SettingOthersPanelSection;
use Botble\Slug\Facades\SlugHelper;
use Botble\SocialLogin\Facades\SocialService;
use Botble\Theme\Events\RenderingThemeOptionSettings;
use Botble\Theme\FormFrontManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class MemberServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register(): void
    {
        config([
            'auth.guards.member' => [
                'driver' => 'session',
                'provider' => 'members',
            ],
            'auth.providers.members' => [
                'driver' => 'eloquent',
                'model' => Member::class,
            ],
            'auth.passwords.members' => [
                'provider' => 'members',
                'table' => 'member_password_resets',
                'expire' => 60,
            ],
        ]);

        $router = $this->app['router'];

        $router->aliasMiddleware('member', RedirectIfNotMember::class);
        $router->aliasMiddleware('member.guest', RedirectIfMember::class);

        $this->app->bind(MemberInterface::class, function () {
            return new MemberRepository(new Member());
        });

        $this->app->bind(MemberActivityLogInterface::class, function () {
            return new MemberActivityLogRepository(new MemberActivityLog());
        });
    }

    public function boot(): void
    {
        SlugHelper::setPrefix(Member::class, 'author');
        SlugHelper::setColumnUsedForSlugGenerator(Member::class, 'last_name');

        add_filter(IS_IN_ADMIN_FILTER, [$this, 'setInAdmin'], 24);

        $this
            ->setNamespace('plugins/member')
            ->loadHelpers()
            ->loadAndPublishConfigurations(['general', 'permissions', 'email'])
            ->loadAndPublishTranslations()
            ->loadAndPublishViews()
            ->loadRoutes(['web', 'member'])
            ->loadMigrations()
            ->publishAssets();

        DashboardMenu::default()->beforeRetrieving(function (): void {
            DashboardMenu::registerItem(
                DashboardMenuItem::make()
                    ->id('cms-core-member')
                    ->priority(50)
                    ->parentId(null)
                    ->name('plugins/member::member.menu_name')
                    ->icon('ti ti-users')
                    ->url(fn () => route('member.index'))
                    ->permissions(['member.index'])
            );
        });

        DashboardMenu::for('member')->beforeRetrieving(function (): void {
            DashboardMenu::make()
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-member-dashboard')
                        ->priority(10)
                        ->name('plugins/member::member.dashboard')
                        ->url(fn () => route('public.member.dashboard'))
                        ->icon('ti ti-home')
                )
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-member-posts')
                        ->priority(20)
                        ->name('plugins/blog::posts.posts')
                        ->url(fn () => route('public.member.posts.index'))
                        ->icon('ti ti-article')
                )
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-member-settings')
                        ->priority(30)
                        ->name('plugins/member::dashboard.header_settings_link')
                        ->url(fn () => route('public.member.settings'))
                        ->icon('ti ti-settings')
                );
        });

        DashboardMenu::default();

        PanelSectionManager::default()->beforeRendering(function (): void {
            PanelSectionManager::registerItem(
                SettingOthersPanelSection::class,
                fn () => PanelSectionItem::make('members')
                    ->setTitle(trans('plugins/member::settings.title'))
                    ->withIcon('ti ti-user-cog')
                    ->withPriority(170)
                    ->withDescription(trans('plugins/member::settings.description'))
                    ->withRoute('member.settings')
            );
        });

        if (class_exists('ApiHelper') && ApiHelper::enabled()) {
            ApiHelper::setConfig([
                'model' => Member::class,
                'guard' => 'member',
                'password_broker' => 'members',
                'verify_email' => setting('verify_account_email', config('plugins.member.general.verify_email')),
            ]);
        }

        $this->app->booted(function (): void {
            EmailHandler::addTemplateSettings(MEMBER_MODULE_SCREEN_NAME, config('plugins.member.email', []));

            if (
                defined('SOCIAL_LOGIN_MODULE_SCREEN_NAME') &&
                ! $this->app->runningInConsole() &&
                Route::has('public.member.login')
            ) {
                SocialService::registerModule([
                    'guard' => 'member',
                    'model' => Member::class,
                    'login_url' => route('public.member.login'),
                    'redirect_url' => route('public.member.dashboard'),
                ]);
            }
        });

        add_filter('social_login_before_saving_account', function ($data, $oAuth, $providerData) {
            if (Arr::get($providerData, 'model') == Member::class && Arr::get($providerData, 'guard') == 'member') {
                $firstName = implode(' ', explode(' ', $oAuth->getName(), -1));
                Arr::forget($data, 'name');
                $data = array_merge($data, [
                    'first_name' => $firstName,
                    'last_name' => trim(str_replace($firstName, '', $oAuth->getName())),
                ]);
            }

            return $data;
        }, 49, 3);

        $this->app->register(EventServiceProvider::class);

        FormAbstract::beforeRendering(function (FormAbstract $form) {
            if (is_plugin_active('language') && is_plugin_active('language-advanced')) {
                $this->loadRoutes(['language-advanced']);

                $adminLocale = Language::getCurrentAdminLocaleCode();

                $isDefaultLocale = $adminLocale == Language::getDefaultLocaleCode();

                $model = $form->getModel();

                if (
                    Route::current() &&
                    in_array('member', Route::current()->middleware()) &&
                    Auth::guard('member')->check() &&
                    ! $isDefaultLocale &&
                    $model &&
                    $model instanceof Member &&
                    $model->getKey() &&
                    LanguageAdvancedManager::isSupported($model)
                ) {
                    $refLang = '?ref_lang=' . $adminLocale;

                    $form->setFormOption('url', route('public.member.language-advanced.save', $model->getKey()) . $refLang);
                }
            }

            return $form;
        }, 9999);

        $this->app['events']->listen(RenderingThemeOptionSettings::class, function (): void {
            add_action(RENDERING_THEME_OPTIONS_PAGE, [$this, 'addThemeOptions'], 35);
        });

        FormFrontManager::register(ForgotPasswordForm::class, ForgotPasswordRequest::class);
        FormFrontManager::register(LoginForm::class, LoginRequest::class);
        FormFrontManager::register(RegisterForm::class, RegisterRequest::class);
        FormFrontManager::register(ResetPasswordForm::class, ResetPasswordRequest::class);

        $this->app->booted(function (): void {
            if (is_plugin_active('blog')) {
                PostForm::beforeRendering(function (PostForm $form) {
                    $authors = Member::query()
                        ->select(['id', 'first_name', 'last_name'])
                        ->get()
                        ->mapWithKeys(function ($author) {
                            return [
                                $author->id => $author->name,
                            ];
                        })
                        ->all();

                    $form
                        ->when($authors, function (PostForm $form) use ($authors): void {
                            $form
                                ->addAfter(
                                    'status',
                                    'author_id',
                                    SelectField::class,
                                    SelectFieldOption::make()
                                        ->label(trans('plugins/member::member.author'))
                                        ->helperText(trans('plugins/member::member.author_helper'))
                                        ->choices($authors)
                                        ->searchable()
                                        ->emptyValue(trans('plugins/member::member.select_author'))
                                        ->allowClear()
                                )
                                ->add(
                                    'author_type',
                                    HiddenField::class,
                                    HiddenFieldOption::make()
                                        ->value(Member::class)
                                );
                        });

                    return $form;
                });
            }
        });

        add_action(
            BASE_ACTION_TOP_FORM_CONTENT_NOTIFICATION,
            function (Request $request, Model|string|null $data = null): void {
                if (! setting('verify_account_email', false)) {
                    return;
                }

                if (! $data instanceof Member || Route::currentRouteName() !== 'member.edit') {
                    return;
                }

                if (Auth::user()->hasPermission('member.edit')) {
                    echo view(
                        'plugins/member::includes.notification',
                        compact('data')
                    )->render();
                }
            },
            45,
            2
        );

        add_filter('social_login_before_creating_account', function ($data) {
            if (! setting('member_enabled_registration', true)) {
                return (new BaseHttpResponse())
                    ->setError()
                    ->setMessage(trans('auth.failed'));
            }

            return $data;
        }, 49);
    }

    public function setInAdmin(bool $isInAdmin): bool
    {
        $segment = request()->segment(1);

        if ($segment && in_array($segment, BaseLanguage::getLocaleKeys()) && $segment !== App::getLocale()) {
            $segment = request()->segment(2);
        }

        return $segment === 'account' || $isInAdmin;
    }

    public function addThemeOptions(): void
    {
        theme_option()
            ->setSection([
                'title' => trans('plugins/member::member.theme_options.name'),
                'id' => 'opt-text-subsection-member',
                'subsection' => true,
                'icon' => 'ti ti-user',
                'fields' => [
                    [
                        'id' => 'login_background',
                        'type' => 'mediaImage',
                        'label' => trans('plugins/member::member.theme_options.login_background_image'),
                        'attributes' => [
                            'name' => 'login_background',
                        ],
                    ],
                    [
                        'id' => 'register_background',
                        'type' => 'mediaImage',
                        'label' => trans('plugins/member::member.theme_options.register_background_image'),
                        'attributes' => [
                            'name' => 'register_background',
                        ],
                    ],
                ],
            ]);
    }
}
