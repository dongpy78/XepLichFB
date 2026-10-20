<?php

namespace Botble\Block\Forms;

use Botble\Base\Forms\FieldOptions\CodeEditorFieldOption;
use Botble\Base\Forms\FieldOptions\ContentFieldOption;
use Botble\Base\Forms\FieldOptions\DescriptionFieldOption;
use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\CodeEditorField;
use Botble\Base\Forms\Fields\EditorField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\Block\Http\Requests\BlockRequest;
use Botble\Block\Models\Block;

class BlockForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->model(Block::class)
            ->setValidatorClass(BlockRequest::class)
            ->add('name', TextField::class, NameFieldOption::make()->required())
            ->add(
                'alias',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('core/base::forms.alias'))
                    ->placeholder(trans('core/base::forms.alias_placeholder'))
                    ->required()
                    ->maxLength(120)
            )
            ->add('description', TextareaField::class, DescriptionFieldOption::make())
            ->add(
                'content',
                EditorField::class,
                ContentFieldOption::make()
                    ->helperText(trans('plugins/block::block.content_helper_text'))
            )
            ->add(
                'raw_content',
                CodeEditorField::class,
                CodeEditorFieldOption::make()
                    ->label(trans('plugins/block::block.raw_content'))
                    ->placeholder(trans('plugins/block::block.raw_content_placeholder'))
                    ->helperText(trans('plugins/block::block.raw_content_helper_text'))
                    ->mode('html')
                    ->rows(4)
            )
            ->add('status', SelectField::class, StatusFieldOption::make())
            ->setBreakFieldPoint('status');
    }
}
