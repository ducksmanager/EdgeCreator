<?php
include_once 'Viewer_wizard.php';
class Sv_doublons extends EC_Controller {
	static $pays;
	static $magazine;
	static $etape_courante;
	static $etape;

	function index($pays=null,$magazine=null) {

		if (in_array(null, [$pays,$magazine])) {
			$this->load->view('errorview', ['Erreur'=>'Nombre d\'arguments insuffisant']);
			exit();
		}
		self::$pays=$pays;
		self::$magazine=$magazine;

		if (is_null($this->session->userdata('user'))) {
			echo 'Aucun utilisateur connecte';
			return;
		}



		$this->load->model($this->session->userdata('Modele_tranche_Wizard','Modele_tranche');
		$this->Modele_tranche->setUsername($this->session->userdata('user'));
		$this->Modele_tranche->sv_doublons($pays,$magazine);

	}
}
