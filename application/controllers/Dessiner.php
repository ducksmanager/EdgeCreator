<?php
include_once APPPATH.'controllers/Viewer_wizard.php';

class Dessiner extends EC_Controller {

	function index() {
		$arguments = func_get_args();

		$this->load->model('Modele_tranche_Wizard','Modele_tranche');
		$nom_fonction = $arguments[0];
		if (class_exists($nom_fonction)) {
			Viewer_wizard::$zoom=$arguments[1];
			Viewer_wizard::$is_debug=$arguments[2] != 0;
			$options=new CountableObject();
			$instance_classe=new $nom_fonction(null,false,false,false);
			$i=3;
			foreach(array_keys($instance_classe::$champs) as $nom_champ) {
				$options->$nom_champ = $arguments[$i++];
			}
			//print_r($options);
			switch($arguments[0]) {
				case 'Arc_cercle':
					$largeur=$options->Pos_x_centre+$options->Largeur/2;
					$hauteur=$options->Pos_y_centre+$options->Hauteur/2;
					Viewer_wizard::$image=imagecreatetruecolor(z($largeur), z($hauteur));
					$transp=imagecolorallocatealpha(Viewer_wizard::$image, 255, 255, 255, 127);
					imagefill(Viewer_wizard::$image,0,0,$transp);
					imagesavealpha(Viewer_wizard::$image, true);

					new Arc_cercle($options);
					if (Viewer_wizard::$is_debug===false) {
                        header('Content-type: image/png');
                    }
					imagepng(Viewer_wizard::$image);
				break;
				case 'Polygone':
					$liste_x=explode(',',$options->X);
					$liste_y=explode(',',$options->Y);
					Viewer_wizard::$image=imagecreatetruecolor(z(max($liste_x)), z(max($liste_y)));
					$transp=imagecolorallocatealpha(Viewer_wizard::$image, 255, 255, 255, 127);
					imagefill(Viewer_wizard::$image,0,0,$transp);
					imagesavealpha(Viewer_wizard::$image, true);

					new Polygone($options);
					if (Viewer_wizard::$is_debug===false) {
                        header('Content-type: image/png');
                    }
					imagepng(Viewer_wizard::$image);
				break;
			}
		}
		else {
			echo 'Erreur : '.$arguments[0]. 'n\'est pas une classe';
		}
	}
}
