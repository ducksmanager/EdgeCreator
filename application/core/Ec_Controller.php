<?php
include_once(BASEPATH.'/../application/helpers/dm_client.php');

class EC_Controller extends CI_Controller {

    /**
     * @var Modele_tranche_Wizard
     */
    var $Modele_tranche;

    function __construct()
    {
        parent::__construct();
        DmClient::initCoaServers();
    }

    function init_model() {
        $this->load->model('Modele_tranche_Wizard','Modele_tranche');
    }
}