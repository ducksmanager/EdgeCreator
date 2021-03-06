<?php
class Numerosdispos extends EC_Controller {

	function index($pays=null,$magazine=null,$get_tranches_non_pretes=false) {
		if ($pays === 'null') {
            $pays = null;
        }
		if ($magazine === 'null') {
            $magazine = null;
        }
		$get_tranches_non_pretes = $get_tranches_non_pretes === 'true';

		$this->init_model();

		$this->Modele_tranche->setUsername($this->session->userdata('user'));

		if (is_null($pays)) {
			if ($get_tranches_non_pretes) {
				$data= ['mode'=>'get_tranches_non_pretes'];
				$data['tranches_non_pretes']=$this->Modele_tranche->get_tranches_non_pretes();
			}
			else {
				$data= ['mode'=>'get_pays'];
				$pays=$this->Modele_tranche->get_pays();
				$data['pays']=$pays;
			}
		}
		else if (is_null($magazine)) {
			$data= ['mode'=>'get_magazines'];
			$magazines=$this->Modele_tranche->get_magazines($pays);
			$data['magazines']=$magazines;

		}
		else {
			$data= ['mode'=>'get_numeros'];
			[$numeros_dispos, $tranches_pretes] = $this->Modele_tranche->get_numeros_disponibles($pays, $magazine, true);

			$nb_etapes=$this->Modele_tranche->get_nb_etapes($pays,$magazine);

            $noms_complets_pays = DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', '/coa/list/countries/fr', [$pays]);
			$noms_complets_magazines = DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', '/coa/list/publications', [$pays . '/' . $magazine]);

			$data['numeros_dispos']=$numeros_dispos;
			$data['tranches_pretes']=$tranches_pretes;
			$data['nb_etapes']=$nb_etapes;
			$data['nom_magazine']=$noms_complets_pays->$pays.' ('.$noms_complets_magazines->{$pays.'/'.$magazine}.')';

		}
		$this->load->view('numerosdisposview',$data);
	}
}
