<?php
namespace Core\Extensions;

use Core\Libraries\FreedomCore\System\Database as Database;

class Website {

    /**
     * Website Database Connection PDO Object Reference
     * @var
     */
    protected $Connection;

    /**
     * Smarty Reference For Local Use
     * @var
     */
    protected $TM;

    /**
     * Website constructor.
     */
    public function __construct($TemplatesManager){
        $this->Connection = Database::$Connections['Website'];
        $this->TM = $TemplatesManager;
    }

    public function getInstalledPatches(){
        return Database::plainSQLPDO($this->Connection, 'SELECT * FROM installed_patches', true, true);
    }

}