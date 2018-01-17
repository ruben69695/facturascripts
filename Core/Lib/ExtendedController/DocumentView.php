<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2018  Carlos Garcia Gomez  <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Core\Lib\ExtendedController;

use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Core\Model\Cliente;
use FacturaScripts\Core\Model\Ejercicio;
use FacturaScripts\Core\Model\Proveedor;

/**
 * Description of DocumentView
 *
 * @author Carlos García Gómez
 */
class DocumentView extends BaseView
{

    /**
     *
     * @var string
     */
    public $documentType;

    /**
     *
     * @var string
     */
    private $lineModelName;

    /**
     * Line columns from xmlview.
     * 
     * @var array
     */
    private $lineOptions;

    /**
     * Lines of document, the body.
     *
     * @var Base\LineaDocumentoVenta[]|Base\LineaDocumentoCompra[]
     */
    public $lines;

    public function __construct($title, $modelName, $lineModelName, $lineXMLView, $userNick)
    {
        parent::__construct($title, $modelName);
        $this->documentType = 'sale';

        // Loads the view configuration for the user
        $this->pageOption->getForUser($lineXMLView, $userNick);

        $this->lineModelName = $lineModelName;
        $this->lineOptions = [];
        foreach ($this->pageOption->columns['root']->columns as $col) {
            $this->lineOptions[] = $col;
        }

        $this->lines = [];
    }

    public function disableColumn($columnName, $disabled)
    {        
    }

    /**
     * Returns the data of lines to the view.
     *
     * @return string
     */
    public function getLineData()
    {
        $data = [
            'headers' => [],
            'columns' => [],
            'rows' => []
        ];

        $moneyFormat = '0.';
        for ($num = 0; $num < FS_NF0; $num++) {
            $moneyFormat .= '0';
        }

        foreach ($this->lineOptions as $col) {
            $data['headers'][] = self::$i18n->trans($col->title);

            $item = [
                'data' => $col->widget->fieldName,
                'type' => $col->widget->type,
            ];
            if ($col->display === 'none') {
                $item['editor'] = false;
                $item['width'] = 1;
            }
            if ($item['type'] === 'number' || $item['type'] === 'money') {
                $item['type'] = 'numeric';
                $item['format'] = $moneyFormat;
            }
            $data['columns'][] = $item;
        }

        foreach ($this->lines as $line) {
            $data['rows'][] = (array) $line;
        }

        return json_encode($data);
    }

    /**
     * Method to export the view data
     *
     * @param ExportManager $exportManager
     */
    public function export(&$exportManager)
    {
        $exportManager->generateDocumentPage($this->model);
    }

    public function loadData($code = false, $where = [], $order = [], $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        if ($this->newCode !== null) {
            $code = $this->newCode;
        }

        if (is_array($code)) {
            $where = [];
            foreach ($code as $fieldName => $value) {
                $where[] = new DataBaseWhere($fieldName, $value);
            }
            $this->model->loadFromCode('', $where);
        } else {
            $this->model->loadFromCode($code);
        }

        $fieldName = $this->model->primaryColumn();
        $this->count = empty($this->model->{$fieldName}) ? 0 : 1;
        $this->lines = empty($this->model->{$fieldName}) ? [] : $this->model->getLineas();
        $this->title = $this->model->codigo;
    }

    public function saveDocument(&$data)
    {
        $result = 'OK';
        $newLines = isset($data['lines']) ? $this->processFormLines($data['lines']) : [];
        unset($data['lines']);
        $this->loadFromData($data);

        if (empty($this->model->codejercicio)) {
            $ejercicioModel = new Ejercicio();
            $ejercicio = $ejercicioModel->getByFecha($this->model->fecha);
            if ($ejercicio) {
                $this->model->codejercicio = $ejercicio->codejercicio;
            }
        }

        if ($this->documentType === 'sale' && empty($this->model->nombrecliente)) {
            $result = $this->setCustomer($data);
        } elseif ($this->documentType === 'purchase' && empty($this->model->nombre)) {
            $result = $this->setSupplier($data);
        }

        if ($result !== 'OK') {
            return $result;
        }

        $new = empty($this->model->primaryColumnValue());
        if ($this->save()) {
            $result = $this->saveLines($newLines);
        } else {
            $result = 'ERROR';
        }

        if ($result === 'OK') {
            return $new ? 'NEW:' . $this->model->url() : $result;
        }

        $miniLog = new MiniLog();
        foreach ($miniLog->read() as $msg) {
            $result = $msg['message'];
        }

        return $result;
    }

    private function setCustomer($data)
    {
        $cliente = new Cliente();
        if ($cliente->loadFromCode($this->model->codcliente)) {
            $this->model->nombrecliente = $cliente->razonsocial;
            $this->model->cifnif = $cliente->cifnif;
            return 'OK';
        }

        if ($data['new_cliente'] !== '') {
            $cliente->nombre = $cliente->razonsocial = $data['new_cliente'];
            $cliente->cifnif = $data['new_cifnif'];
            if ($cliente->save()) {
                $this->model->codcliente = $cliente->codcliente;
                $this->model->nombrecliente = $cliente->razonsocial;
                $this->model->cifnif = $cliente->cifnif;
                return 'OK';
            }
        }

        return 'ERROR: NO CUSTOMER';
    }

    private function setSupplier($data)
    {
        $proveedor = new Proveedor();
        if ($proveedor->loadFromCode($this->model->codproveedor)) {
            $this->model->nombre = $proveedor->razonsocial;
            $this->model->cifnif = $proveedor->cifnif;
            return 'OK';
        }

        if ($data['new_proveedor'] !== '') {
            $proveedor->nombre = $proveedor->razonsocial = $data['new_proveedor'];
            $proveedor->cifnif = $data['new_cifnif'];
            if ($proveedor->save()) {
                $this->model->codproveedor = $proveedor->codproveedor;
                $this->model->nombre = $proveedor->razonsocial;
                $this->model->cifnif = $proveedor->cifnif;
                return 'OK';
            }
        }

        return 'ERROR: NO SUPPLIER';
    }

    private function saveLines(&$newLines)
    {
        $result = 'OK';

        /// remove or modify old lines
        foreach ($this->lines as $oldLine) {
            $found = false;
            foreach ($newLines as $newLine) {
                if ($newLine['idlinea'] == $oldLine->idlinea) {
                    $found = true;
                    if (!$this->updateLine($oldLine, $newLine)) {
                        $result = 'ERROR ON LINE: ' . $oldLine->idlinea;
                    }
                    break;
                }
            }

            if (!$found) {
                $oldLine->delete();
            }
        }

        /// add new lines
        $lineClass = $this->lineModelName;
        foreach ($newLines as $newLine) {
            if (empty($newLine['idlinea']) && !empty($newLine['descripcion'])) {
                $newDocLine = new $lineClass($newLine);
                $newDocLine->idlinea = null;
                $newDocLine->{$this->model->primaryColumn()} = $this->model->primaryColumnValue();
                $newDocLine->pvpsindto = $newDocLine->pvpunitario * $newDocLine->cantidad;
                $newDocLine->pvptotal = $newDocLine->pvpsindto * (100 - $newDocLine->dtopor) / 100;

                if (!$newDocLine->save()) {
                    $result = "ERROR ON NEW LINE";
                }
            }
        }

        return $result;
    }

    public function setNewCode()
    {
        
    }

    /**
     * Updates oldLine with newLine data.
     * 
     * @param mixed $oldLine
     * @param array $newLine
     * 
     * @return bool
     */
    protected function updateLine($oldLine, $newLine)
    {
        foreach ($newLine as $key => $value) {
            $oldLine->{$key} = $value;
        }

        $oldLine->pvpsindto = $oldLine->pvpunitario * $oldLine->cantidad;
        $oldLine->pvptotal = $oldLine->pvpsindto * (100 - $oldLine->dtopor) / 100;

        return $oldLine->save();
    }

    /**
     * Process form lines to assign column keys instead of numbers.
     * Also adds order column.
     * 
     * @param array $formLines
     * 
     * @return array
     */
    protected function processFormLines($formLines)
    {
        $newLines = [];
        $columns = [];
        foreach ($this->lineOptions as $col) {
            $columns[] = $col->widget->fieldName;
        }

        $order = count($formLines);
        foreach ($formLines as $data) {
            $line = ['orden' => $order];
            foreach ($data as $key => $value) {
                $line[$columns[$key]] = $value;
            }
            $newLines[] = $line;
            $order--;
        }

        return $newLines;
    }
}
