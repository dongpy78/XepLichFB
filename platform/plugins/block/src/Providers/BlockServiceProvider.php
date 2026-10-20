<?php

namespace Botble\Block\Providers;

use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Forms\FormAbstract;
use Botble\Base\Supports\DashboardMenuItem;
use Botble\Base\Supports\ServiceProvider;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\Block\Models\Block;
use Botble\Block\Repositories\Eloquent\BlockRepository;
use Botble\Block\Repositories\Interfaces\BlockInterface;
use Botble\CustomField\Facades\CustomField;
use Botble\LanguageAdvanced\Supports\LanguageAdvancedManager;

class BlockServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register(): void
    {
        $this->app->bind(BlockInterface::class, function () {
            return new BlockRepository(new Block());
        });
    }

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/block')
            ->loadHelpers()
            ->loadAndPublishConfigurations(['permissions'])
            ->loadAndPublishTranslations()
            ->loadRoutes()
            ->loadAndPublishViews()
            ->loadMigrations();

        DashboardMenu::default()->beforeRetrieving(function (): void {
            DashboardMenu::make()
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-plugins-block')
                        ->priority(410)
                        ->name('plugins/block::block.menu')
                        ->icon('ti ti-code')
                        ->route('block.index')
                );
        });

        if (defined('LANGUAGE_ADVANCED_MODULE_SCREEN_NAME')) {
            LanguageAdvancedManager::registerModule(Block::class, [
                'name',
                'description',
                'content',
                'raw_content',
            ]);
        }

        $this->app->booted(function (): void {
            FormAbstract::beforeRendering(function (): void {
                if (defined('CUSTOM_FIELD_MODULE_SCREEN_NAME')) {
                    CustomField::registerModule(Block::class)
                        ->registerRule('basic', trans('plugins/block::block.name'), Block::class, function () {
                            return Block::query()
                                ->select([
                                    'id',
                                    'name',
                                ])->latest()
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->expandRule(
                            'other',
                            trans('plugins/custom-field::rules.model_name'),
                            'model_name',
                            function () {
                                return [
                                    Block::class => trans('plugins/block::block.name'),
                                ];
                            }
                        );
                }
            });

            $this->app->register(HookServiceProvider::class);
        });
    }
}
