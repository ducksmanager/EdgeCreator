<?php
include_once APPPATH.'models/Modele_tranche.php';

class Modele_tranche_Wizard extends Modele_tranche {
	static $content_fields = ['Ordre', 'Nom_fonction', 'Option_nom', 'Option_valeur'];
	static $numero;

    function get_tranches_en_cours($id_modele=null) {

        if (!is_null($id_modele)) {
            $resultats = DmClient::get_service_results_ec(
                DmClient::$dm_server, 'GET', "/edgecreator/v2/model/$id_modele"
            );
            $resultats = [$resultats];
		}
		else {
            $resultats = DmClient::get_service_results_ec(
                DmClient::$dm_server, 'GET', '/edgecreator/v2/model'
            );
        }
		self::assigner_noms_magazines($resultats);
		return $resultats;
	}

    function get_tranches_en_attente() {
        $resultats = DmClient::get_service_results_ec(
            DmClient::$dm_server, 'GET', '/edgecreator/v2/model/editedbyother/all'
        );
        self::assigner_noms_magazines($resultats);

        return $resultats;
	}

    function get_tranches_en_attente_d_edition() {
        $resultats = DmClient::get_service_results_ec(
            DmClient::$dm_server, 'GET', '/edgecreator/v2/model/unassigned/all'
        );
        self::assigner_noms_magazines($resultats);

        return $resultats;
	}

	static function assigner_noms_magazines(&$resultats) {
        if (count($resultats) > 0) {
            $liste_magazines= array_map(function($resultat) {
                return implode('/', [$resultat->pays, $resultat->magazine]);
            }, $resultats);

            $noms_magazines = DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', '/coa/list/publications', [implode(',', array_unique($liste_magazines))]);

            foreach($resultats as $resultat) {
                $publicationcode = implode('/', [$resultat->pays, $resultat->magazine]);
                $resultat->magazine_complet=$noms_magazines->{$publicationcode};
            }
        }
    }

	function get_etapes_by_id($id_modele) {
		$resultats_ordres= [];
		$requete="
          SELECT DISTINCT Ordre, Nom_fonction
          FROM tranches_en_cours_valeurs
          WHERE ID_Modele = $id_modele
          ORDER BY Ordre";
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
		foreach($resultats as $resultat) {
			$resultats_ordres[$resultat->Ordre]=$resultat->Nom_fonction;
		}
		return $resultats_ordres;
	}

	function get_etapes_simple() {
        $id_modele = $this->session->userdata('id_modele');

		$requete="
          SELECT Ordre, Nom_fonction, Option_nom, Option_valeur
          FROM tranches_en_cours_valeurs
          WHERE ID_Modele = $id_modele
          GROUP BY Ordre
          ORDER BY Ordre";
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
        foreach($resultats as &$resultat) {
            $resultat->Ordre = (int) $resultat->Ordre;
        }
		return $resultats;
	}

	function get_fonction_ec_v2($ordre, $id_modele = null) {
        $id_modele = $id_modele ?? $this->session->userdata('id_modele');
		$requete="
          SELECT Ordre, Nom_fonction, Option_nom, Option_valeur
          FROM tranches_en_cours_valeurs
          WHERE ID_Modele = $id_modele AND Ordre=$ordre";

        $premier_resultat = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')[0];
		return !is_object($premier_resultat) ? null : new Fonction($premier_resultat);
	}

    function get_options_ec_v2(
        $ordre,
        $inclure_infos_options = false,
        $nouvelle_etape = false,
        $nom_option = null,
        $id_modele = null
    ) {
        if (is_null($id_modele)) {
            $id_modele = $this->session->userdata('id_modele');
        }

        $requete="
          SELECT Ordre, Nom_fonction, Option_nom, Option_valeur
          FROM tranches_en_cours_valeurs
          WHERE ID_Modele = $id_modele AND Ordre=$ordre AND Option_nom IS NOT NULL ";
        if (!is_null($nom_option)) {
            $requete .= "AND Option_nom = '$nom_option' ";
        }
        $requete.= 'ORDER BY Option_nom ASC';

        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
        $resultats_options=new CountableObject();
        foreach($resultats as $resultat) {
            $resultats_options->{$resultat->Option_nom} = $resultat->Option_valeur;
        }
        $nom_fonction = $resultats[0]->Nom_fonction;
        $f=new $nom_fonction($resultats_options,false,false,!$nouvelle_etape); // Ajout des champs avec valeurs par défaut
        if ($inclure_infos_options) {
            $prop_champs=new ReflectionProperty(get_class($f), 'champs');
            $champs=$prop_champs->getValue();
            $prop_valeurs_defaut=new ReflectionProperty(get_class($f), 'valeurs_defaut');
            $valeurs_defaut=$prop_valeurs_defaut->getValue();
            $prop_descriptions=new ReflectionProperty(get_class($f), 'descriptions');
            $descriptions=$prop_descriptions->getValue();
            foreach(array_keys((array)$f->options) as $nom_option) {
                $intervalles_option=[];
                $intervalles_option['valeur']=$f->options->$nom_option;
                $intervalles_option['type']=$champs[$nom_option];
                $intervalles_option['description']= $descriptions[$nom_option] ?? '';
                if (array_key_exists($nom_option, $valeurs_defaut)) {
                    $intervalles_option['valeur_defaut'] = $valeurs_defaut[$nom_option];
                }
                $f->options->$nom_option=$intervalles_option;
            }
        }
        return $f->options;
    }

	function has_no_option_ec_v2() {
        $id_modele = $this->session->userdata('id_modele');

		$requete="
		  SELECT Option_nom
		  FROM tranches_en_cours_valeurs
		  WHERE ID_Modele = $id_modele AND Option_nom IS NOT NULL";
        return count(DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')) === 0;
	}

	function decaler_etapes_a_partir_de($id_modele,$etape_debut, $inclure_cette_etape) {
        $inclure_cette_etape = $inclure_cette_etape ? 'inclusive' : 'exclusive';

        $resultat = DmClient::get_service_results_ec(
            DmClient::$dm_server, 'POST', "/edgecreator/v2/step/shift/$id_modele/$etape_debut/$inclure_cette_etape", []
        );

		return $resultat->shifts;
	}

	function valeur_existe($id_valeur) {
		$requete='SELECT ID FROM edgecreator_valeurs WHERE ID='.$id_valeur;
        return count(DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')) > 0;
	}

	function insert_to_modele($id_modele,$ordre,$nom_fonction,$option_nom,$option_valeur) {
		$option_nom=is_null($option_nom) ? 'NULL' : '\''.preg_replace("#([^\\\\])'#","$1\\'",$option_nom).'\'';
		$option_valeur=is_null($option_valeur) ? 'NULL' : '\''.preg_replace("#([^\\\\])'#","$1\\'",$option_valeur).'\'';

		$requete='INSERT INTO tranches_en_cours_valeurs (ID_Modele,Ordre,Nom_fonction,Option_nom,Option_valeur) VALUES '
				.'('.$id_modele.','.$ordre.',\''.$nom_fonction.'\','.$option_nom.','.$option_valeur.') ';
        DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
	}

	function get_id_modele($pays,$magazine,$numero,$username=null) {
		if (is_null($username)) {
			$username = self::$username;
		}
		$requete='SELECT ID FROM tranches_en_cours_modeles '
				.'WHERE Pays=\''.$pays.'\' AND Magazine=\''.$magazine.'\' AND Numero=\''.$numero.'\'';
		if (!is_null($username)) {
			$requete.=' AND username=\''.$username.'\'';
		}
        $resultat = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')[0];
		return $resultat->ID;
	}

	function get_nom_fonction($id_modele,$ordre) {
		$requete='SELECT Nom_fonction FROM tranches_en_cours_valeurs '
				.'WHERE ID_Modele='.$id_modele.' AND Ordre='.$ordre;
        $resultat = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')[0];
		return $resultat->Nom_fonction;
	}

	function creer_modele($pays, $magazine, $numero) {
        $est_editeur = in_array($this->get_privilege(), ['Edition', 'Admin']) ? '1' : '0';
        $resultat = DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'PUT',
            "/edgecreator/v2/model/$pays/$magazine/$numero/$est_editeur"
        );
        return $resultat->modelid;
	}

	function get_photo_principale() {
        $id_modele = $this->session->userdata('id_modele');
        if (is_null($id_modele)) {
            return null;
        }

        $resultat = DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', "/edgecreator/model/v2/$id_modele/photo/main");
        if (is_null($resultat)) {
            return null;
        }

        return $resultat->nomfichier;
    }

	function insert_etape($pos_relative, $etape, $nom_fonction) {
        $id_modele = $this->session->userdata('id_modele');
		$inclure_avant = $pos_relative==='avant' || $etape === -1;
		$infos=new CountableObject();

		if ($etape > -1) {
		    $infos->decalages=$this->decaler_etapes_a_partir_de($id_modele,$etape, $inclure_avant);
        }
        else {
		    $infos->decalages = [];
        }

		$nouvelle_fonction=new $nom_fonction(false, null, true);
		$numero_etape=$inclure_avant ? $etape : $etape+1;

        DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'POST',
            "/edgecreator/v2/step/$id_modele/$numero_etape",
            [
                'stepfunctionname' => $nom_fonction,
                'options' => $nouvelle_fonction->options
            ]
        );

		$infos->numero_etape=$numero_etape;
		return $infos;
	}

	function update_etape($etape,$parametrage) {
        $id_modele = $this->session->userdata('id_modele');

        DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'POST',
            "/edgecreator/v2/step/$id_modele/$etape",
            ['options' => $parametrage]
        );
    }

	function update_photo_principale($nom_photo_principale) {
        $id_modele = $this->session->userdata('id_modele');

        DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'PUT',
            "/edgecreator/model/v2/$id_modele/photo/main", [
                'photoname' => $nom_photo_principale
            ]
        );
	}

	function cloner_etape_numero($pos, $etape_courante) {
        $id_modele = $this->session->userdata('id_modele');

		$inclure_avant = $pos==='avant' || $pos==='_';
		$infos=new CountableObject();

		$infos->decalages=$this->decaler_etapes_a_partir_de($id_modele,$etape_courante, $inclure_avant);
		if ($inclure_avant) {
            $etape_courante++;
            $nouvelle_etape=$etape_courante-1;
        }
        else {
            $nouvelle_etape=$etape_courante+1;
        }

        $resultat = DmClient::get_service_results_ec(DmClient::$dm_server, 'POST', "/edgecreator/v2/step/clone/$id_modele/$etape_courante/to/$nouvelle_etape", []
        );

		$infos->numero_etape=$nouvelle_etape;
		$infos->nom_fonction=$resultat->functionName;
		return $infos;
	}

	function supprimer_etape($etape) {
        $id_modele = $this->session->userdata('id_modele');

        DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'DELETE',
            "/edgecreator/v2/step/$id_modele/$etape"
        );
	}

	function delete_option($pays,$magazine,$etape,$nom_option) {
		if ($nom_option === 'Actif') {
            $requete_suppr_option = 'DELETE modeles, valeurs, intervalles FROM edgecreator_modeles2 modeles '
                . 'INNER JOIN edgecreator_valeurs AS valeurs ON modeles.ID = valeurs.ID_Option '
                . 'INNER JOIN edgecreator_intervalles AS intervalles ON valeurs.ID = intervalles.ID_Valeur '
                . 'WHERE Pays = \'' . $pays . '\' AND Magazine = \'' . $magazine . '\' '
                . 'AND Ordre=' . $etape . ' AND Option_nom IS NULL AND username = \'' . self::$username . '\'';
        }
		else {
            $requete_suppr_option = 'DELETE modeles, valeurs, intervalles FROM edgecreator_modeles2 modeles '
                . 'INNER JOIN edgecreator_valeurs AS valeurs ON modeles.ID = valeurs.ID_Option '
                . 'INNER JOIN edgecreator_intervalles AS intervalles ON valeurs.ID = intervalles.ID_Valeur '
                . 'WHERE Pays = \'' . $pays . '\' AND Magazine = \'' . $magazine . '\' '
                . 'AND Ordre=' . $etape . ' AND Option_nom = \'' . $nom_option . '\' AND username = \'' . self::$username . '\'';
        }
        DmClient::get_query_results_from_dm_server($requete_suppr_option, 'db_edgecreator');
		echo $requete_suppr_option."\n";
	}

	function get_id_modele_tranche_en_cours_max() {
		$requete='SELECT MAX(ID) AS Max FROM tranches_en_cours_modeles';
        return DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')[0]->Max;
	}

	function etendre_numero ($pays,$magazine,$numero,$nouveau_numero) {
        $options = $this->get_valeurs_options($pays,$magazine, [$numero]);

		if (count($options[$numero]) === 0) {
			echo 'Aucune option d\'étape pour '.$pays.'/'.$magazine.' '.$numero;
		}
		else {
            $id_modele = DmClient::get_service_results_ec(
                DmClient::$dm_server,
                'POST',
                "/edgecreator/v2/model/clone/to/$pays/$magazine/$nouveau_numero", [
                    'steps' => $options[$numero]['etapes']
                ]
            )->modelid;

            // TODO return model ID and non-cloned steps
            return [
                'id_modele' => $id_modele,
                'etapes_non_clonees' => []
            ];
        }
        return [];
	}

	function get_tranches_non_pretes() {
		$username = $this->session->userdata('user');
		$id_user = $this->username_to_id($username);
		$requete= ' SELECT ID, Pays,Magazine,Numero'
				. ' FROM numeros'
				. ' WHERE ID_Utilisateur=' .$id_user
				."   AND CONCAT(Pays,'/',Magazine,' ',Numero) NOT IN"
				."    (SELECT CONCAT(publicationcode,' ',issuenumber)"
				. '   FROM tranches_pretes)'
				. ' ORDER BY Pays, Magazine, Numero';

		$resultats = $this->requete_select_dm($requete);

        $publication_codes = array_map(function($resultat) {
		    return $resultat['Pays'].'/'.$resultat['Magazine'];
        }, $resultats);

        $noms_magazines = DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'GET',
            '/coa/list/publications',
            [implode(',', array_unique($publication_codes))]
        );

		foreach($resultats as &$resultat) {
			$resultat['Magazine_complet'] = $noms_magazines[$resultat['Pays'].'/'.$resultat['Magazine']];
		}

		return $resultats;
	}

	function desactiver_modele() {
        $id_modele = $this->session->userdata('id_modele');

        DmClient::get_service_results_ec(DmClient::$dm_server, 'POST', "/edgecreator/model/v2/$id_modele/deactivate");
	}

    public function ajouter_photo_tranches_multiples($nomFichier, $hash)
    {
        DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'PUT',
            '/edgecreator/multiple_edge_photo', [
                'hash' => $hash,
                'filename' => $nomFichier
        ]);
    }

    public function est_limite_photos_atteinte()
    {
        $photos_jour = DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'GET',
            '/edgecreator/multiple_edge_photo/today'
        );

        return count($photos_jour) > 10;
    }

    public function get_photo_existante($hash)
    {
        return DmClient::get_service_results_ec(
            DmClient::$dm_server,
            'GET',
            "/edgecreator/multiple_edge_photo/hash/$hash"
        );
    }

    function copier_image_temp_vers_gen($nom_image) {
        $id_modele = $this->session->userdata('id_modele');

        $model = DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', "/edgecreator/v2/model/$id_modele");

        $src_image =  self::getCheminImages().'/' . $model->pays . '/tmp/' . $nom_image . '.png';
        $dest_image = self::getCheminImages().'/' . $model->pays . '/gen/' . $model->magazine . '.' . $model->numero . '.png';
        @mkdir(self::getCheminImages().'/' . $model->pays . '/tmp');
        @unlink($dest_image);
        echo "Copy of $src_image to $dest_image";
        return copy($src_image, $dest_image);
    }

	function publier($createurs, $photographes) {
        $id_modele = $this->session->userdata('id_modele');

        $result = DmClient::get_service_results_ec(DmClient::$dm_server, 'PUT', "/edgecreator/publish/$id_modele", [
            'photographers' => explode(',', $photographes),
            'designers' => explode(',', $createurs)
        ]);
        $id_edge = $result->edgeId;
        DmClient::get_service_results_admin(DmClient::$dm_server, 'PUT', "/edgesprites/$id_edge");
    }

	function get_couleurs_frequentes() {
        $id_modele = $this->session->userdata('id_modele');
		$couleurs= [];
		$requete= "
		  SELECT DISTINCT Option_valeur
		  FROM tranches_en_cours_valeurs
		  WHERE ID_Modele=$id_modele AND Option_nom LIKE 'Couleur%'";
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
		foreach($resultats as $resultat) {
			$couleurs[]=$resultat->Option_valeur;
		}
		return $couleurs;
	}

	function get_couleur_point_photo($frac_x,$frac_y) {
        $id_modele = $this->session->userdata('id_modele');
		$requete_nom_photo = "
		    SELECT images.NomFichier, modeles.Pays
            FROM tranches_en_cours_modeles modeles
            INNER JOIN tranches_en_cours_modeles_images modeles_images on modeles.ID = modeles_images.ID_Modele
            INNER JOIN images_tranches images ON modeles_images.ID_Image = images.ID
            WHERE modeles.ID=$id_modele
		";

        $resultat_nom_photo = DmClient::get_query_results_from_dm_server($requete_nom_photo, 'db_edgecreator')[0];

		$chemin_photos = Fonction_executable::getCheminPhotos($resultat_nom_photo->Pays);
		$chemin_photo_tranche = $chemin_photos.'/'.$resultat_nom_photo->NomFichier;
		$image = imagecreatefromjpeg($chemin_photo_tranche);
		[$width, $height] = getimagesize($chemin_photo_tranche);

		$rgb = imagecolorat($image, $frac_x*$width, $frac_y*$height);
		$r = ($rgb >> 16) & 0xFF;
		$g = ($rgb >> 8) & 0xFF;
		$b = $rgb & 0xFF;
		return rgb2hex($r,$g,$b);
	}

    public function get_autres_modeles_utilisant_fichier($nomFichier)
    {
        $resultats = DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', "/edgecreator/elements/images/$nomFichier");

        $userdata = $this->session->userdata();
        return array_filter($resultats, function($resultat) use ($userdata) {
            return !($resultat->Pays === $userdata['pays'] && $resultat->Magazine === $userdata['magazine'] && $resultat->Numero_debut === $userdata['numero'] && $resultat->Numero_fin === $userdata['numero']);
        });
    }
}
