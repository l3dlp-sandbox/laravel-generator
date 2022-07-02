<?php

namespace InfyOm\Generator\Generators\Scaffold;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InfyOm\Generator\Generators\BaseGenerator;
use InfyOm\Generator\Generators\ViewServiceProviderGenerator;
use InfyOm\Generator\Utils\HTMLFieldGenerator;

class ViewGenerator extends BaseGenerator
{
    private string $templateType;

    public function __construct()
    {
        parent::__construct();

        $this->path = $this->config->paths->views;
        $this->templateType = config('laravel_generator.templates', 'adminlte-templates');
    }

    public function generate()
    {
        if (!file_exists($this->path)) {
            mkdir($this->path, 0755, true);
        }

        $htmlInputs = Arr::pluck($this->config->fields, 'htmlInput');
        if (in_array('file', $htmlInputs)) {
            $this->config->addDynamicVariable('$FILES$', ", 'files' => true");
        }

        $this->config->commandComment(PHP_EOL.'Generating Views...');

        if ($this->config->getOption('views')) {
            $viewsToBeGenerated = explode(',', $this->config->getOption('views'));

            if (in_array('index', $viewsToBeGenerated)) {
                $this->generateTable();
                $this->generateIndex();
            }

            if (count(array_intersect(['create', 'update'], $viewsToBeGenerated)) > 0) {
                $this->generateFields();
            }

            if (in_array('create', $viewsToBeGenerated)) {
                $this->generateCreate();
            }

            if (in_array('edit', $viewsToBeGenerated)) {
                $this->generateUpdate();
            }

            if (in_array('show', $viewsToBeGenerated)) {
                $this->generateShowFields();
                $this->generateShow();
            }
        } else {
            $this->generateTable();
            $this->generateIndex();
            $this->generateFields();
            $this->generateCreate();
            $this->generateUpdate();
            $this->generateShowFields();
            $this->generateShow();
        }

        $this->config->commandComment('Views created: ');
    }

    private function generateTable()
    {
        if ($this->config->tableType === 'livewire') {
            return;
        }

        switch ($this->config->tableType) {
            case 'blade':
                $templateData = $this->generateBladeTableBody();
                $paginateTemplate = get_template('scaffold.views.paginate', $this->templateType);

                $paginateTemplate = fill_template($this->config->dynamicVars, $paginateTemplate);

                $templateData = str_replace('$PAGINATE$', $paginateTemplate, $templateData);
                break;

            case 'datatables':
                $templateData = $this->generateDataTableBody();
                $this->generateDataTableActions();
                break;

            default:
                throw new Exception('Invalid Table Type');
        }

        g_filesystem()->createFile($this->path.'table.blade.php', $templateData);

        $this->config->commandInfo('table.blade.php created');
    }

    private function generateDataTableBody(): string
    {
        $templateData = get_template('scaffold.views.table.datatable.body', $this->templateType);

        return fill_template($this->config->dynamicVars, $templateData);
    }

    private function generateDataTableActions()
    {
        $templateName = 'table.datatable.actions';

        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }

        $templateData = get_template('scaffold.views.'.$templateName, $this->templateType);

        $templateData = fill_template($this->config->dynamicVars, $templateData);

        g_filesystem()->createFile($this->path.'datatables_actions.blade.php', $templateData);

        $this->config->commandInfo('datatables_actions.blade.php created');
    }

    private function generateBladeTableBody(): string
    {
        $templateName = 'table.blade.body';

        $tableFields = $this->generateTableHeaderFields();

        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }

        $templateData = get_template('scaffold.views.'.$templateName, $this->templateType);

        $templateData = fill_template($this->config->dynamicVars, $templateData);

        $templateData = str_replace('$FIELD_HEADERS$', $tableFields, $templateData);

        $cellFieldTemplate = get_template('scaffold.views.table.blade.cell', $this->templateType);

        $tableBodyFields = [];

        foreach ($this->config->fields as $field) {
            if (!$field->inIndex) {
                continue;
            }

            $tableBodyFields[] = fill_template_with_field_data(
                $this->config->dynamicVars,
                ['$FIELD_NAME_TITLE$' => 'fieldTitle', '$FIELD_NAME$' => 'name'],
                $cellFieldTemplate,
                $field
            );
        }

        $tableBodyFields = implode(infy_nl_tab(1, 3), $tableBodyFields);

        return str_replace('$FIELD_BODY$', $tableBodyFields, $templateData);
    }

    private function generateTableHeaderFields(): string
    {
        $templateName = 'table.blade.header';

        $localized = false;
        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
            $localized = true;
        }

        $headerFieldTemplate = get_template('scaffold.views.'.$templateName, $this->templateType);

        $headerFields = [];

        foreach ($this->config->fields as $field) {
            if (!$field->inIndex) {
                continue;
            }

            if ($localized) {
                /**
                 * Replacing $FIELD_NAME$ before fill_template_with_field_data_locale() otherwise also
                 * $FIELD_NAME$ get replaced with @lang('models/$modelName.fields.$value')
                 * and so we don't have $FIELD_NAME$ in table_header_locale.stub
                 * We could need 'raw' field name in header for example for sorting.
                 * We still have $FIELD_NAME_TITLE$ replaced with @lang('models/$modelName.fields.$value').
                 *
                 * @see issue https://github.com/InfyOmLabs/laravel-generator/issues/887
                 */
                $preFilledHeaderFieldTemplate = str_replace('$FIELD_NAME$', $field->name, $headerFieldTemplate);

                $headerFields[] = fill_template_with_field_data_locale(
                    $this->config->dynamicVars,
                    ['$FIELD_NAME_TITLE$' => 'fieldTitle', '$FIELD_NAME$' => 'name'],
                    $preFilledHeaderFieldTemplate,
                    $field
                );
            } else {
                $headerFields[] = fill_template_with_field_data(
                    $this->config->dynamicVars,
                    ['$FIELD_NAME_TITLE$' => 'fieldTitle', '$FIELD_NAME$' => 'name'],
                    $headerFieldTemplate,
                    $field
                );
            }
        }

        return implode(infy_nl_tab(1, 2), $headerFields);
    }

    private function generateIndex()
    {
        $templateName = 'index';

        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }

        $templateData = get_template('scaffold.views.'.$templateName, $this->templateType);

        $templateData = fill_template($this->config->dynamicVars, $templateData);

        switch ($this->config->tableType) {
            case 'datatables':
            case 'blade':
                $tableReplaceString = fill_template(
                    $this->config->dynamicVars,
                    "@include('\$VIEW_PREFIX$\$MODEL_NAME_PLURAL_SNAKE$.table')"
                );
                break;

            case 'livewire':
                $tableTemplate = get_template('scaffold.views.table.livewire.body', $this->templateType);

                $tableReplaceString = fill_template(
                    $this->config->dynamicVars,
                    $tableTemplate
                );
                break;

            default:
                throw new Exception('Invalid table type');
        }

        $templateData = str_replace('$TABLE$', $tableReplaceString, $templateData);
        g_filesystem()->createFile($this->path.'index.blade.php', $templateData);

        $this->config->commandInfo('index.blade.php created');
    }

    private function generateFields()
    {
        $templateName = 'fields';

        $localized = false;
        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
            $localized = true;
        }

        $htmlFields = [];

        foreach ($this->config->fields as $field) {
            if (!$field->inForm) {
                continue;
            }

            $validations = explode('|', $field->validations);
            $minMaxRules = '';
            foreach ($validations as $validation) {
                if (!Str::contains($validation, ['max:', 'min:'])) {
                    continue;
                }

                $validationText = substr($validation, 0, 3);
                $sizeInNumber = substr($validation, 4);

                $sizeText = ($validationText == 'min') ? 'minlength' : 'maxlength';
                if ($field->htmlType == 'number') {
                    $sizeText = $validationText;
                }

                $size = ",'$sizeText' => $sizeInNumber";
                $minMaxRules .= $size;
            }
            $this->config->addDynamicVariable('$SIZE$', $minMaxRules);

            $fieldTemplate = HTMLFieldGenerator::generateHTML($field, $this->templateType, $localized);

            if ($field->htmlType == 'selectTable') {
                $inputArr = explode(',', $field->htmlValues[1]);
                $columns = '';
                foreach ($inputArr as $item) {
                    $columns .= "'$item'".',';  //e.g 'email,id,'
                }
                $columns = substr_replace($columns, '', -1); // remove last ,

                $htmlValues = explode(',', $field->htmlValues[0]);
                $selectTable = $htmlValues[0];
                $modalName = null;
                if (count($htmlValues) == 2) {
                    $modalName = $htmlValues[1];
                }

                $tableName = $this->config->tableName;
                $viewPath = $this->config->prefixes->view;
                if (!empty($viewPath)) {
                    $tableName = $viewPath.'.'.$tableName;
                }

                $variableName = Str::singular($selectTable).'Items'; // e.g $userItems

                $fieldTemplate = $this->generateViewComposer($tableName, $variableName, $columns, $selectTable, $modalName);
            }

            if (!empty($fieldTemplate)) {
                $fieldTemplate = fill_template_with_field_data(
                    $this->config->dynamicVars,
                    ['$FIELD_NAME_TITLE$' => 'fieldTitle', '$FIELD_NAME$' => 'name'],
                    $fieldTemplate,
                    $field
                );
                $htmlFields[] = $fieldTemplate;
            }
        }

        $templateData = get_template('scaffold.views.'.$templateName, $this->templateType);
        $templateData = fill_template($this->config->dynamicVars, $templateData);

        $templateData = str_replace('$FIELDS$', implode("\n\n", $htmlFields), $templateData);

        g_filesystem()->createFile($this->path.'fields.blade.php', $templateData);
        $this->config->commandInfo('field.blade.php created');
    }

    private function generateViewComposer($tableName, $variableName, $columns, $selectTable, $modelName = null): string
    {
        $templateName = 'scaffold.fields.select';
        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }
        $fieldTemplate = get_template($templateName, $this->templateType);

        $viewServiceProvider = new ViewServiceProviderGenerator();
        $viewServiceProvider->generate();
        $viewServiceProvider->addViewVariables($tableName.'.fields', $variableName, $columns, $selectTable, $modelName);

        return str_replace(
            '$INPUT_ARR$',
            '$'.$variableName,
            $fieldTemplate
        );
    }

    private function generateCreate()
    {
        $templateName = 'create';

        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }

        $templateData = get_template('scaffold.views.'.$templateName, $this->templateType);

        $templateData = fill_template($this->config->dynamicVars, $templateData);

        g_filesystem()->createFile($this->path.'create.blade.php', $templateData);
        $this->config->commandInfo('create.blade.php created');
    }

    private function generateUpdate()
    {
        $templateName = 'edit';

        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }

        $templateData = get_template('scaffold.views.'.$templateName, $this->templateType);

        $templateData = fill_template($this->config->dynamicVars, $templateData);

        g_filesystem()->createFile($this->path.'edit.blade.php', $templateData);
        $this->config->commandInfo('edit.blade.php created');
    }

    private function generateShowFields()
    {
        $templateName = 'show_field';
        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }
        $fieldTemplate = get_template('scaffold.views.'.$templateName, $this->templateType);

        $fieldsStr = '';

        foreach ($this->config->fields as $field) {
            if (!$field->inView) {
                continue;
            }
            $singleFieldStr = str_replace(
                '$FIELD_NAME_TITLE$',
                Str::title(str_replace('_', ' ', $field->name)),
                $fieldTemplate
            );
            $singleFieldStr = str_replace('$FIELD_NAME$', $field->name, $singleFieldStr);
            $singleFieldStr = fill_template($this->config->dynamicVars, $singleFieldStr);

            $fieldsStr .= $singleFieldStr."\n\n";
        }

        g_filesystem()->createFile($this->path.'show_fields.blade.php', $fieldsStr);
        $this->config->commandInfo('show_fields.blade.php created');
    }

    private function generateShow()
    {
        $templateName = 'show';

        if ($this->config->isLocalizedTemplates()) {
            $templateName .= '_locale';
        }

        $templateData = get_template('scaffold.views.'.$templateName, $this->templateType);

        $templateData = fill_template($this->config->dynamicVars, $templateData);

        g_filesystem()->createFile($this->path.'show.blade.php', $templateData);
        $this->config->commandInfo('show.blade.php created');
    }

    public function rollback($views = [])
    {
        $files = [
            'table.blade.php',
            'index.blade.php',
            'fields.blade.php',
            'create.blade.php',
            'edit.blade.php',
            'show.blade.php',
            'show_fields.blade.php',
        ];

        if (!empty($views)) {
            $files = [];
            foreach ($views as $view) {
                $files[] = $view.'.blade.php';
            }
        }

        if ($this->config->tableType === 'datatables') {
            $files[] = 'datatables_actions.blade.php';
        }

        foreach ($files as $file) {
            if ($this->rollbackFile($this->path, $file)) {
                $this->config->commandComment($file.' file deleted');
            }
        }
    }
}
