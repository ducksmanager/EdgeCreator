<?php

class Viewer_defaut extends EC_Controller {
    static $is_debug=false;

    function index() {
        echo 'Hello';
    }

	function edges() { // Input path is for instance : /edges/fr/gen/MP.1000.png
	    [$pays, , $magazine_numero] = func_get_args();
	    [$magazine, $numero,] = explode('.', $magazine_numero);
	    $zoom = 1.5;

        $this->init_model();

        $largeur_defaut = 15;
        $hauteur_defaut = 200;

        $image = $this->Modele_tranche->defaut($pays, $magazine, $numero, $zoom, $largeur_defaut, $hauteur_defaut);
        $this->Modele_tranche::save_image($pays, $magazine, $numero, $image);
	}
}
