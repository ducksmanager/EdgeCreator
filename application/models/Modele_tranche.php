<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');

class Modele_tranche extends CI_Model {
	static $just_connected;
	static $pays;
	static $magazine;
	static $username;
	static $random_id;
	static $numero_debut;
	static $numero_fin;
	static $numeros_dispos;
	static $dropdown_numeros;
	static $fields = ['Pays', 'Magazine', 'Ordre', 'Nom_fonction', 'Option_nom', 'Option_valeur', 'Numero_debut', 'Numero_fin'];
	static $user_possede_modele;
	static $utilisateurs = [];
	static $noms_fonctions = [
        'Agrafer','Arc_cercle','Degrade','DegradeTrancheAgrafee', 'Image','Polygone','Rectangle','Remplir','TexteMyFonts'
    ];

	function __construct($tab= [])
	{
		foreach($tab as $arg_name=>$arg_value) {
            $this->$arg_name = $arg_value;
        }
		parent::__construct();
		$_SESSION['lang']='fr';
	}

    public static function getCheminImages()
    {
        return BASEPATH . '../../edges/';
    }

    public static function getCheminPolices()
    {
        return BASEPATH . 'fonts/';
    }

    function get_just_connected() {
		return self::$just_connected;
	}

	function requete_select_dm($requete) {
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_dm');
        if (is_array($resultats)) {
            return array_map(function($result) {
                return (array) $result;
            }, $resultats);
        }
        return [];
	}

	function get_privilege() {
	    global $erreur;
		$privilege=null;
		if (isset($_REQUEST['user'], $_REQUEST['pass'])) {
			self::$just_connected=true;
            $privilege = $this->get_privilege_from_username($_REQUEST['user'], $_REQUEST['pass'], isset($_REQUEST['is_sha1']));
			if (is_null($privilege)) {
                if (!empty($erreur)) {
                    ErrorHandler::error_log($erreur);
                }
                return null;
            }

            $this->creer_id_session($_REQUEST['user'], $_REQUEST['pass'], $privilege);
        }
		else {
            $privilege = $this->session->userdata('privilege') ?? 'Affichage';
		}
		return $privilege;
	}

	function setUtilisateurs() {
	    if (empty(self::$utilisateurs)) {
            $requete_utilisateurs='SELECT ID, username FROM users';
            $resultat_utilisateurs=$this->requete_select_dm($requete_utilisateurs);
            foreach ($resultat_utilisateurs as $utilisateur) {
                self::$utilisateurs[$utilisateur['ID']]=$utilisateur['username'];
            }
        }
	}

	function get_privilege_from_username($user, &$pass, $isSha1Pass = false) {
		if (!$isSha1Pass) {
		    $pass=sha1($pass);
        }
		global $erreur;
        $requete="
            SELECT 
              users.username,
              (SELECT privilege FROM users_permissions WHERE username = users.username AND users_permissions.role = 'EdgeCreator') AS privilege
            FROM users
            WHERE users.username ='$user' AND users.password = '$pass'";
        $resultat = $this->requete_select_dm($requete);
        if (count($resultat) === 0) {
            $erreur = 'Identifiants invalides !';
            return null;
        }
        return $resultat[0]['privilege'] ?? 'Affichage';
    }

	function username_to_id($username) {
        if (count(self::$utilisateurs) === 0) {
            $this->setUtilisateurs();
        }
        return array_search($username, self::$utilisateurs);
	}

	function user_exists($user) {
        if (count(self::$utilisateurs) === 0) {
            $this->setUtilisateurs();
        }
        return in_array($user, self::$utilisateurs);
	}


	function creer_id_session($user, $pass, $privilege) {
		$this->session->set_userdata(['user' => $user, 'pass' => $pass, 'privilege' => $privilege]);
	}

	function user_possede_modele($pays=null,$magazine=null,$username=null) {
		if (is_null($pays)) {
            $pays = self::$pays;
        }
		if (is_null($magazine)) {
            $magazine = self::$magazine;
        }
		if (is_null($username)) {
            $username = self::$username;
        }
		if (is_null(self::$user_possede_modele)) {
			$requete_modele_magazine_existe='SELECT Count(1) AS cpt FROM edgecreator_modeles2 '
										   .'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
										   .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
										   .'WHERE Pays = \''.$pays.'\' AND Magazine = \''.$magazine.'\' AND username = \''.$username.'\'';
			$user_possede_modele = DmClient::get_query_results_from_dm_server($requete_modele_magazine_existe, 'db_edgecreator')[0]->cpt > 0;
            self::$user_possede_modele = $user_possede_modele;
		}
		return self::$user_possede_modele;
	}

	function dupliquer_modele_magazine_si_besoin($pays,$magazine) {
		if (!$this->user_possede_modele($pays,$magazine,self::$username)) {
			$options=$this->get_modeles_magazine($pays,$magazine);
			foreach($options as $option) {
				$this->insert($option->Pays, $option->Magazine, $option->Ordre, $option->Nom_fonction,
                    $option->Option_nom, $option->Option_valeur, $option->Numero_debut, $option->Numero_fin);

			}
		}
	}

	function get_modeles_magazine($pays,$magazine,$ordre=null)
	{
		$resultats_o= [];
		$requete='SELECT '.implode(', ', self::$fields).' '
				.'FROM edgecreator_modeles2 '
				.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
				.'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
				.'WHERE Pays = \''.$pays.'\' AND Magazine = \''.$magazine.'\' '
				.'AND username = \''.self::$username.'\' ';
		if (!is_null($ordre)) {
            $requete .= 'AND Ordre=' . $ordre . ' ';
        }
		$requete.='ORDER BY Ordre';
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');

		foreach($resultats as $resultat) {
            $resultats_o[] = new Modele_tranche ($resultat);
        }
		return $resultats_o;
	}

	function get_etapes($pays, $magazine, $numero=null) {
		$resultats_ordres= [];
		$requete='SELECT DISTINCT Ordre, Numero_debut, Numero_fin '
				.'FROM edgecreator_modeles2 '
				.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
			    .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
			    .'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' '
				.'AND username LIKE \''.self::$username.'\' '
				.'ORDER BY Ordre';
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
		foreach($resultats as $resultat) {
			if (!is_null($numero)) {
				$numeros_debut=explode(';',$resultat->Numero_debut);
				$numeros_fin=explode(';',$resultat->Numero_fin);
				foreach($numeros_debut as $i=>$numero_debut) {
					$numero_fin=$numeros_fin[$i];
					$intervalle=$this->getIntervalleShort($this->getIntervalle($numero_debut, $numero_fin));
					if (!est_dans_intervalle($numero, $intervalle)) {
                        continue;
                    }
				}

			}
			$resultats_ordres[]=$resultat->Ordre;
		}
		$resultats_ordres=array_unique($resultats_ordres);
		return $resultats_ordres;
	}

	function get_nb_etapes($pays,$magazine) {
		$requete='SELECT Count(Nom_fonction) AS cpt '
				.'FROM edgecreator_modeles2 '
				.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
			    .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
			    .'WHERE Pays = \''.$pays.'\' AND Magazine = \''.$magazine.'\' AND Option_nom IS NULL '
				.'AND username = \''.self::$username.'\'';
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');

		return $resultats[0]->cpt;
	}

	function get_etapes_simple_magazine($pays,$magazine,$num_etape=null) {
		$resultats_etapes= [];
		$requete='SELECT DISTINCT Ordre, Nom_fonction, edgecreator_valeurs.ID AS ID_Valeur '
				.'FROM edgecreator_modeles2 '
				.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
			    .'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Option_nom IS NULL '
				.'AND EXISTS (SELECT 1 FROM edgecreator_intervalles WHERE edgecreator_intervalles.ID_Valeur = edgecreator_valeurs.ID AND username LIKE \''.self::$username.'\') ';
		if (!is_null($num_etape)) {
            $requete .= 'AND Ordre=' . $num_etape . ' ';
        }
		$requete.=' GROUP BY Ordre'
				 .' ORDER BY Ordre ';
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
		foreach($resultats as $resultat) {
			$resultat->Numero_debut= [];
			$resultat->Numero_fin= [];
			$requete_intervalles='SELECT Numero_debut, Numero_fin '
								.'FROM edgecreator_modeles2 '
								.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
								.'INNER JOIN edgecreator_intervalles ON edgecreator_intervalles.ID_Valeur = edgecreator_valeurs.ID '
			    				.'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Ordre='.$resultat->Ordre.' AND Option_nom IS NULL ';
            $resultats_intervalles = DmClient::get_query_results_from_dm_server($requete_intervalles, 'db_edgecreator');
			foreach($resultats_intervalles as $intervalle) {
				$resultat->Numero_debut[]=$intervalle->Numero_debut;
				$resultat->Numero_fin[]=$intervalle->Numero_fin;
			}
			$resultat->Numero_debut=implode(';',$resultat->Numero_debut);
			$resultat->Numero_fin=implode(';',$resultat->Numero_fin);
			$resultats_etapes[]=$resultat;
		}
		return $resultats_etapes;
	}

	function get_fonction($pays,$magazine,$ordre,$numero=null) {
		$requete='SELECT '.implode(', ', self::$fields).' '
				.'FROM edgecreator_modeles_vue '
				.'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Ordre='.$ordre.' AND Option_nom IS NULL '
				.'AND username LIKE \''.self::$username.'\'';
        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
		if (count($resultats) === 0) {
			return null;
		}
		$numeros_debut= [];
		$numeros_fin= [];
		foreach($resultats as $resultat) {
			$intervalle=$this->getIntervalleShort($this->getIntervalle($resultat->Numero_debut, $resultat->Numero_fin));
			if (!is_null($numero) && !est_dans_intervalle($numero, $intervalle)) {
                continue;
            }

			$numeros_debut[]=$resultat->Numero_debut;
			$numeros_fin[]=$resultat->Numero_fin;
		}
		$resultat_tous_intervalles=$resultat;
		$resultat_tous_intervalles->Numero_debut=implode(';',$numeros_debut);
		$resultat_tous_intervalles->Numero_fin=implode(';',$numeros_fin);

		return new Fonction($resultat_tous_intervalles);
	}

	function get_options(
        $pays,
        $magazine,
        $ordre,
        $nom_fonction,
        $numero = null,
        $inclure_infos_options = false,
        $nouvelle_etape = false,
        $nom_option = null
    ) {
		$creation=false;
		$resultats_options=new CountableObject();
		$requete='SELECT '.implode(', ', self::$fields).' '
				.'FROM edgecreator_modeles2 '
				.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
			    .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
				.'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Ordre='.$ordre.' AND Option_nom IS NOT NULL '
				.'AND username LIKE \''.self::$username.'\' ';
		if (!is_null($nom_fonction)) {
            $requete .= 'AND Nom_fonction LIKE \'' . $nom_fonction . '\' ';
        }
		if (!is_null($nom_option)) {
            $requete .= 'AND Option_nom LIKE \'' . $nom_option . '\' ';
        }
		$requete.='ORDER BY Option_nom ASC';

        $resultats = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
		$option_nom='';
		foreach($resultats as $resultat) {
			if ($option_nom!==$resultat->Option_nom) {
				$option_courante= [];
				if (!empty($option_nom) && is_null($numero)) {
                    uksort($resultats_options->$option_nom, 'trier_intervalles');
                }
			}
			$nom_fonction=$resultat->Nom_fonction;
			$option_nom=$resultat->Option_nom;
			$numeros_debut=explode(';',$resultat->Numero_debut);
			$numeros_fin=explode(';',$resultat->Numero_fin);
			foreach($numeros_debut as $i=>$numero_debut) {
				$numero_fin=$numeros_fin[$i];
				$intervalle=$this->getIntervalleShort($this->getIntervalle($numero_debut, $numero_fin));
				if (est_dans_intervalle($numero, $intervalle)) {
					if (is_null($numero)) {
                        $option_courante[$intervalle] = $resultat->Option_valeur;
                    }
					else {
                        $option_courante = $resultat->Option_valeur;
                    }
					continue;
				}
			}
			$resultats_options->$option_nom=$option_courante;
		}
		if (is_null($numero) && isset($resultats_options->$option_nom)) {
            uksort($resultats_options->$option_nom, 'trier_intervalles');
        }

		$f=new $nom_fonction($resultats_options,false,$creation,!$nouvelle_etape); // Ajout des champs avec valeurs par défaut
		if ($inclure_infos_options) {
			$prop_champs=new ReflectionProperty(get_class($f), 'champs');
			$champs=$prop_champs->getValue();
			$prop_valeurs_defaut=new ReflectionProperty(get_class($f), 'valeurs_defaut');
			$valeurs_defaut=$prop_valeurs_defaut->getValue();
			$prop_descriptions=new ReflectionProperty(get_class($f), 'descriptions');
			$descriptions=$prop_descriptions->getValue();
			foreach($f->options as $nom_option=>$val) {
				$intervalles_option=$f->options->$nom_option;
				if (!is_array($intervalles_option)) {
                    $intervalles_option = [null => $intervalles_option];
                }
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

	function get_noms_champs($nom_fonction) {
		$f=new $nom_fonction(null,false,false,false);
		$prop_champs=new ReflectionProperty(get_class($f), 'champs');
		$champs=$prop_champs->getValue();
		$prop_valeurs_defaut=new ReflectionProperty(get_class($f), 'valeurs_defaut');
		$valeurs_defaut=$prop_valeurs_defaut->getValue();
		$prop_descriptions=new ReflectionProperty(get_class($f), 'descriptions');
		$descriptions=$prop_descriptions->getValue();

		foreach($f->options as $nom_option=>$val) {
			$intervalles_option=$f->options->$nom_option;
			$intervalles_option['type']=$champs[$nom_option];
			$intervalles_option['description']= $descriptions[$nom_option] ?? '';
			if (array_key_exists($nom_option, $valeurs_defaut)) {
                $intervalles_option['valeur_defaut'] = $valeurs_defaut[$nom_option];
            }
			$f->options->$nom_option=$intervalles_option;
		}
		return $f->options;
	}

	function has_no_option($pays, $magazine) {
		$requete='SELECT Option_nom '
				.'FROM edgecreator_modeles2 '
				.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
			    .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
				.'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Option_nom IS NOT NULL '
				.'AND username LIKE \''.self::$username.'\'';
        return count(DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')) === 0;
	}

	// Obsolete
	function ecv1_decaler_etapes_a_partir_de($pays,$magazine,$etape_debut) {
		$requete='SELECT Max(Ordre) AS max_ordre FROM edgecreator_modeles2 '
				.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
			    .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
				.'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Ordre>='.$etape_debut.' AND username LIKE \''.self::$username.'\' ';
        $resultat = DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')[0];

		if (!is_null($resultat)) {
			$etape=$resultat->max_ordre;
			echo 'Decalage des etapes '.$etape_debut.' a '.$etape."\n";
			for ($i=$etape;$i>=$etape_debut;$i--) {
				$requete='UPDATE edgecreator_modeles2 '
						.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
					    .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
						.'SET Ordre='.($i+1).' '
						.'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Ordre='.$i.' AND username LIKE \''.self::$username.'\'';
                DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
			}
		}
		else {
            echo 'Pas de decalage' . "\n";
        }

	}

	function sv_doublons($pays,$magazine) {
		self::$numeros_dispos=$this->get_numeros_disponibles($pays, $magazine);
		$numeros_disponibles=self::$numeros_dispos;
		unset ($numeros_disponibles['Aucun']);
		$requete_get_etapes='SELECT DISTINCT Ordre '
							  .'FROM edgecreator_modeles2 '
							  .'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
						      .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
						      .'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND username LIKE \''.self::$username.'\'';
        $resultat_get_etapes = DmClient::get_query_results_from_dm_server($requete_get_etapes, 'db_edgecreator');
		$dimensions= [];
		foreach($resultat_get_etapes as $resultat_etape) {
			$etape=$resultat_etape->Ordre;
			$requete_get_options='SELECT Numero_debut, Numero_fin, Option_nom, Option_valeur '
								.'FROM edgecreator_modeles2 '
								.'INNER JOIN edgecreator_valeurs ON edgecreator_modeles2.ID = edgecreator_valeurs.ID_Option '
							    .'INNER JOIN edgecreator_intervalles ON edgecreator_valeurs.ID = edgecreator_intervalles.ID_Valeur '
							    .'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Ordre='.$etape.' AND username LIKE \''.self::$username.'\'';
            $resultat_get_options = DmClient::get_query_results_from_dm_server($requete_get_options, 'db_edgecreator');
			foreach($resultat_get_options as $option) {
				foreach(array_keys($numeros_disponibles) as $numero) {
					Viewer_wizard::$numero=$numero;
					if (!isset($dimensions[$numero])) {
                        $dimensions[$numero] = [];
                    }
					if (isset($dimensions[$numero]['x'], $dimensions[$numero]['y'])) {
						Viewer_wizard::$largeur=$dimensions[$numero]['x'];
						Viewer_wizard::$hauteur=$dimensions[$numero]['y'];
					}
					$intervalle=$option->Numero_debut.'~'.$option->Numero_fin;
					if (est_dans_intervalle($numero,$intervalle)) {
						if (is_string($numeros_disponibles[$numero])) {
                            $numeros_disponibles[$numero] = [];
                        }
						if (!array_key_exists($etape,$numeros_disponibles[$numero])) {
                            $numeros_disponibles[$numero][$etape] = [];
                        }
						$valeur=is_null($option->Option_valeur)?'null':$option->Option_valeur;
						$valeur_calculee=Fonction_executable::toTemplatedString($valeur);
						if ($valeur_calculee !== true) {
                            $valeur = $valeur_calculee;
                        }
						if ($option->Option_nom === 'Dimension_x') {
                            $dimensions[$numero]['x'] = $valeur;
                        }
						if ($option->Option_nom === 'Dimension_y') {
                            $dimensions[$numero]['y'] = $valeur;
                        }
						$numeros_disponibles[$numero][$etape][is_null($option->Option_nom)?'null':$option->Option_nom]=utf8_encode($valeur);
					}
				}
			}
		}
		$groupes_numeros= [];
		foreach(array_keys($numeros_disponibles) as $numero) {
			$numeros_disponibles[$numero]=serialize($numeros_disponibles[$numero]);
		}
		foreach($numeros_disponibles as $numero=>$etapes_serialized) {
			if (!array_key_exists($etapes_serialized, $groupes_numeros)) {
                $groupes_numeros[$etapes_serialized] = [];
            }
			$groupes_numeros[$etapes_serialized][]=$numero;
		}
		foreach($groupes_numeros as $groupe) {
            $nb_numeros = count($groupe);
            if ($nb_numeros > 1) {
				$numero_reference=$groupe[0];
				for ($i=1; $i<$nb_numeros; $i++) {
					$numero=$groupe[$i];
					$requete='INSERT INTO tranches_doublons(Pays,Magazine,Numero,NumeroReference) '
							.'VALUES (\''.$pays.'\',\''.$magazine.'\',\''.$numero.'\',\''.$numero_reference.'\')';
					echo $requete.'<br />';
				}
			}
		}
		$groupes_numeros_lisibles= [];
		foreach($groupes_numeros as $i=>$groupe) {
			$groupes_numeros_lisibles[json_encode(unserialize($i))]=$groupe;
		}
		echo '<pre>';print_r($groupes_numeros_lisibles);echo '</pre>';
	}

	function get_pays() {
		return DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', '/coa/list/countries/fr');
	}

	function get_magazines($pays) {
        return DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', '/coa/list/publications', [$pays]);
	}

	function get_numeros($publicationcode) {
        return DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', '/coa/list/issues', [$publicationcode]);
	}

    static function get_numero_clean($numero) {
        return str_replace([' ', '+'], '', $numero);
    }

	function get_valeurs_options($pays,$magazine,$numeros= []) {
        self::$pays = $pays;
        self::$magazine = $magazine;

        if (count($numeros) === 0) {
            return [];
        }
		$numeros_esc= [];
		foreach($numeros as $numero) {
			$numeros_esc[]='\''.$numero.'\'';
		}

        $requete_get_options_ec_v2= "
          SELECT 1 AS EC_v2, Numero, Ordre, Nom_fonction, Option_nom, Option_valeur
          FROM tranches_en_cours_modeles_vue
          WHERE Pays = '$pays' AND Magazine = '$magazine'
          AND Numero IN (".implode(',', $numeros_esc). ') 
          ORDER BY Ordre';
        $resultats = DmClient::get_query_results_from_dm_server($requete_get_options_ec_v2, 'db_edgecreator');

        $requete_get_options_ec_v1 = '
            SELECT 0 AS EC_v2, ' . implode(', ', self::$fields) . ",username
            FROM edgecreator_modeles2 AS modeles
            INNER JOIN edgecreator_valeurs AS valeurs ON modeles.ID = valeurs.ID_Option
            INNER JOIN edgecreator_intervalles AS intervalles ON valeurs.ID = intervalles.ID_Valeur
            WHERE Pays = '$pays' AND Magazine = '$magazine'
            ORDER BY Ordre";
        $resultats = array_merge($resultats, DmClient::get_query_results_from_dm_server($requete_get_options_ec_v1, 'db_edgecreator'));

		$options= [];

		foreach($resultats as $resultat) {
			$est_ec_v2 = $resultat->EC_v2 === '1';

			foreach($numeros as $numero) {
			    $hasProcessedEcV2Options = array_key_exists($numero, $options) && $options[$numero]['EC_v2'] === true;
			    if ($est_ec_v2 || !$hasProcessedEcV2Options) {
                    if (( $est_ec_v2 && $numero === $resultat->Numero)
                     || (!$est_ec_v2 && est_dans_intervalle(
                            $numero,
                            $this->getIntervalleShort($this->getIntervalle($resultat->Numero_debut, $resultat->Numero_fin)))
                        )) {
                        if (!array_key_exists($numero, $options)) {
                            $options[$numero]= ['EC_v2' =>  $est_ec_v2, 'etapes' => []];
                        }
                        if (!array_key_exists($resultat->Ordre, $options[$numero]['etapes'])) {
                            $options[$numero]['etapes'][$resultat->Ordre]= [
                                'stepfunctionname' => $resultat->Nom_fonction,
                                'options' => []
                            ];
                        }
                        if (!is_null($resultat->Option_nom)) {
                            $options[$numero]['etapes'][$resultat->Ordre]['options'][$resultat->Option_nom]=$resultat->Option_valeur;
                        }
                    }
                }
			}
		}

        foreach($options as &$options_numero) {
            $options_numero['qualite'] = $this->get_qualite_tranche($options_numero['etapes']);
        }

		return $options;
	}

    /**
     * @param array $options_etapes
     * @return string
     */
    private function get_qualite_tranche($options_etapes)
    {
        $qualite_etapes = [];

        foreach($options_etapes as $etape => &$fonction_et_options) {
            $nom_fonction = $fonction_et_options['stepfunctionname'];
            $noms_options = array_keys($fonction_et_options['options']);

            $fonction_et_options['options_manquantes'] = array_diff(array_keys($nom_fonction::$valeurs_defaut), $noms_options);

            $champs_obligatoires = array_diff(array_keys($nom_fonction::$champs), array_keys($nom_fonction::$valeurs_defaut));
            $fonction_et_options['options_manquantes_sans_valeur_defaut'] = array_diff($champs_obligatoires, $noms_options);

            $qualite_etapes[$etape] =
                count($fonction_et_options['options_manquantes_sans_valeur_defaut']) > 0
                    ? 'error'
                    : (count($fonction_et_options['options_manquantes']) > 0
                        ? 'warning' : 'ok');
        }

        return count(array_keys($qualite_etapes, 'error')) > 0
            ? 'error'
            : (count(array_keys($qualite_etapes, 'warning')) > 0
                ? 'warning' : 'ok');
    }

	function get_numeros_disponibles($pays,$magazine,$get_prets=false) {
		self::$pays = $pays;
		self::$magazine = $magazine;

        $id_user=$this->username_to_id(self::$username);
        if (count(self::$utilisateurs) === 0) {
            $this->setUtilisateurs();
        }

        $numeros = $this->get_numeros($pays.'/'.$magazine);

        $numeros_affiches= ['Aucun'=>'Aucun'];
        foreach($numeros as $numero) {
            $numero_clean = self::get_numero_clean($numero);
            $numeros_affiches[] = $numero_clean;
        }

        if ($get_prets) {
            $tranches_pretes = [];

            $liste_numeros = implode(',', array_map(function ($numero) {
                return "'" . $numero . "'";
            }, $numeros_affiches));

            // TODO chunks
            $requete_creations = "
				SELECT Numero AS issuenumber
				FROM tranches_en_cours_modeles modeles 
				WHERE modeles.Active=0 AND modeles.Pays = '$pays' AND Magazine='$magazine' AND Numero IN ($liste_numeros)";
            $resultats_requete_creations = DmClient::get_query_results_from_dm_server($requete_creations, 'db_edgecreator');

            foreach ($resultats_requete_creations as $numero) {
                $tranches_pretes[$numero->issuenumber] = 'en_cours';
            }

			$requete_get_pretes = "
				SELECT 
				  tp.issuenumber,
                  IF((select 1
                   FROM tranches_pretes_contributeurs tp_c
                   where tp_c.publicationcode = tp.publicationcode AND tp_c.issuenumber = tp.issuenumber AND tp_c.contributeur=$id_user AND tp_c.contribution = 'createur'
                  ),'par_moi', 'global') AS type_contributeur
                FROM tranches_pretes tp
                WHERE tp.publicationcode = '$pays/$magazine'";
			$resultat_get_pretes = $this->requete_select_dm($requete_get_pretes);

			foreach ($resultat_get_pretes as $tranche_prete) {
				$tranches_pretes[$tranche_prete['issuenumber']] = $tranche_prete['type_contributeur'];
			}

			return [$numeros_affiches, $tranches_pretes];
        }

        return $numeros_affiches;
    }

	function valeur_existe($id_valeur) {
		$requete='SELECT ID FROM edgecreator_valeurs WHERE ID='.$id_valeur;
        return count(DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')) === 0;
	}

	function insert(
        $pays,
        $magazine,
        $etape,
        $nom_fonction,
        $option_nom,
        $option_valeur,
        $numero_debut,
        $numero_fin,
        $id_valeur = null
    ) {
	    // TODO as DM server service
		$option_nom=is_null($option_nom) ? 'NULL' : '\''.preg_replace("#([^\\\\])'#","$1\\'",$option_nom).'\'';
		$option_valeur=is_null($option_valeur) ? 'NULL' : '\''.preg_replace("#([^\\\\])'#","$1\\'",$option_valeur).'\'';


		$requete='INSERT INTO edgecreator_modeles2 (Pays,Magazine,Ordre,Nom_fonction,Option_nom) VALUES '
				.'(\''.$pays.'\',\''.$magazine.'\',\''.$etape.'\',\''.$nom_fonction.'\','.$option_nom.') ';
		echo $requete."\n";
        DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
		$id_option = $this->db->insert_id();

		if (is_null($id_valeur) || !$this->valeur_existe($id_valeur)) {
			if (is_null($id_valeur)) {
                $requete = 'INSERT INTO edgecreator_valeurs (Option_valeur,ID_Option) VALUES (' . $option_valeur . ',' . $id_option . ')';
            }
			else {
                $requete = 'INSERT INTO edgecreator_valeurs (ID,Option_valeur,ID_Option) VALUES (' . $id_valeur . ',' . $option_valeur . ',' . $id_option . ')';
            }

			echo $requete."\n";
            DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');
			$id_valeur = $this->db->insert_id();
		}
		$requete='INSERT INTO edgecreator_intervalles (ID_Valeur,Numero_debut,Numero_fin,username) VALUES ('.$id_valeur.',\''.$numero_debut.'\',\''.$numero_fin.'\',\''.self::$username.'\')';
		echo $requete."\n";
        DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator');

	}

	function update_ordre($pays,$magazine,$ordre,$numero_debut,$numero_fin,$nom_fonction,$parametrage) {
		$requete_suppr='DELETE modeles, valeurs, intervalles FROM edgecreator_modeles2 AS modeles '
					  .'INNER JOIN edgecreator_valeurs AS valeurs ON modeles.ID = valeurs.ID_Option '
				      .'INNER JOIN edgecreator_intervalles AS intervalles ON valeurs.ID = intervalles.ID_Valeur '
					  .'WHERE (Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' AND Ordre LIKE \''.$ordre.'\' AND Nom_Fonction LIKE \''.$nom_fonction.'\' AND username LIKE \''.self::$username.'\')';
        DmClient::get_query_results_from_dm_server($requete_suppr, 'db_edgecreator');
		echo $requete_suppr."\n";
		$this->insert($pays, $magazine, $ordre, $nom_fonction, null, null, $numero_debut, $numero_fin);

		foreach($parametrage as $option_nom_intervalle=>$option_valeur) {
			$option_valeur=str_replace("'","\'",$option_valeur);
			[$option_nom, $intervalle] = explode('.', $option_nom_intervalle);
			[$numero_debut, $numero_fin] = explode('~', $intervalle);

			$this->insert($pays, $magazine, $ordre, $nom_fonction, $option_nom, $option_valeur, $numero_debut,
                $numero_fin);
		}
	}

	function insert_ordre($pays,$magazine,$ordre,$numero_debut,$numero_fin,$nom_fonction,$parametrage) {
		$ordre_existe=count($this->get_etapes_simple_magazine($pays, $magazine, $ordre)) > 0;
		if ($ordre_existe) {
			return;
		}
		$this->insert($pays, $magazine, $ordre, $nom_fonction, null, null, $numero_debut, $numero_fin);
		foreach($parametrage as $option_nom_intervalle=>$option_valeur) {
			$option_valeur=str_replace("'","\\'",$option_valeur);
			[$option_nom, $intervalle] = explode('.', $option_nom_intervalle);
			[$numero_debut, $numero_fin] = explode('~', $intervalle);

			$this->insert($pays, $magazine, $ordre, $nom_fonction, $option_nom, $option_valeur, $numero_debut,
                $numero_fin);

		}
	}

	function delete_option($pays,$magazine,$etape,$nom_option) {
		if ($nom_option === 'Actif') {
            $requete_suppr_option = 'DELETE modeles, valeurs, intervalles FROM edgecreator_modeles2 modeles '
                . 'INNER JOIN edgecreator_valeurs AS valeurs ON modeles.ID = valeurs.ID_Option '
                . 'INNER JOIN edgecreator_intervalles AS intervalles ON valeurs.ID = intervalles.ID_Valeur '
                . 'WHERE Pays LIKE \'' . $pays . '\' AND Magazine LIKE \'' . $magazine . '\' '
                . 'AND Ordre=' . $etape . ' AND Option_nom IS NULL AND username = \'' . self::$username . '\'';
        }
		else {
            $requete_suppr_option = 'DELETE modeles, valeurs, intervalles FROM edgecreator_modeles2 modeles '
                . 'INNER JOIN edgecreator_valeurs AS valeurs ON modeles.ID = valeurs.ID_Option '
                . 'INNER JOIN edgecreator_intervalles AS intervalles ON valeurs.ID = intervalles.ID_Valeur '
                . 'WHERE Pays LIKE \'' . $pays . '\' AND Magazine LIKE \'' . $magazine . '\' '
                . 'AND Ordre=' . $etape . ' AND Option_nom = \'' . $nom_option . '\' AND username = \'' . self::$username . '\'';
        }
        DmClient::get_query_results_from_dm_server($requete_suppr_option, 'db_edgecreator');
		echo $requete_suppr_option."\n";
	}

	function insert_valeur_option($pays,$magazine,$etape,$nom_fonction,$option_nom,$valeur,$numero_debut,$numero_fin,$id_valeur=null) {
		if ($option_nom === 'Actif') {
			$this->insert($pays, $magazine, $etape, $nom_fonction, null, null, $numero_debut, $numero_fin, $id_valeur);

		}
		else {
            $this->insert($pays, $magazine, $etape, $nom_fonction, $option_nom, $valeur, $numero_debut, $numero_fin,
                $id_valeur);
        }
	}

	function get_id_valeur_max() {
		$requete='SELECT MAX(ID) AS Max FROM edgecreator_valeurs';
        return DmClient::get_query_results_from_dm_server($requete, 'db_edgecreator')[0]->Max;
	}

	function etendre_numero ($pays,$magazine,$numero,$nouveau_numero) {
		$requete_get_options='SELECT '.implode(', ', self::$fields).',username '
						    .'FROM edgecreator_modeles2 AS modeles '
							.'INNER JOIN edgecreator_valeurs AS valeurs ON modeles.ID = valeurs.ID_Option '
					        .'INNER JOIN edgecreator_intervalles AS intervalles ON valeurs.ID = intervalles.ID_Valeur '
							.'WHERE Pays LIKE \''.$pays.'\' AND Magazine LIKE \''.$magazine.'\' '
							.'ORDER BY Ordre';
		echo $requete_get_options."\n";
        $resultats = DmClient::get_query_results_from_dm_server($requete_get_options, 'db_edgecreator');
		foreach($resultats as $resultat) {
			$option_nom=is_null($resultat->Option_nom) ? 'NULL' : ('\''.$resultat->Option_nom.'\'');
			$option_valeur=is_null($resultat->Option_valeur) ? 'NULL' : ('\''.$resultat->Option_valeur.'\'');
			$intervalle=$this->getIntervalleShort($this->getIntervalle($resultat->Numero_debut, $resultat->Numero_fin));
			if (est_dans_intervalle($numero, $intervalle) && !est_dans_intervalle($nouveau_numero, $intervalle)) {
                $intervalle=$this->ajouterNumeroAIntervalle($intervalle, $nouveau_numero);

                $condition_option_nom=is_null($resultat->Option_nom) ? 'IS NULL' : '= '.$option_nom;
                $condition_option_valeur=is_null($resultat->Option_nom) ? 'IS NULL' : '= '.$option_valeur;
                $requete_id_valeur='SELECT edgecreator_modeles_vue.ID_Valeur AS ID '
                                  .'FROM edgecreator_modeles_vue '
                                  .'WHERE Pays = \''.$resultat->Pays.'\' AND Magazine = \''.$resultat->Magazine.'\' '
                                  .'AND Ordre = \''.$resultat->Ordre.'\' AND Nom_fonction = \''.$resultat->Nom_fonction.'\' '
                                  .'AND Option_nom '.$condition_option_nom.' AND Option_valeur '.$condition_option_valeur.' '
                                  .'AND Numero_debut = \''.$resultat->Numero_debut.'\' AND Numero_fin = \''.$resultat->Numero_fin.'\' '
                                  .'AND username = \''.$resultat->username.'\'';
                echo $requete_id_valeur."\n";
$id_valeur = DmClient::get_query_results_from_dm_server($requete_id_valeur, 'db_edgecreator')[0]->ID;


                $req_suppression_existantes='DELETE FROM edgecreator_intervalles '
                                           .'WHERE ID_Valeur='.$id_valeur.' AND Numero_debut = \''.$resultat->Numero_debut.'\' AND Numero_fin = \''.$resultat->Numero_fin.'\' '
                                           .'AND username = \''.$resultat->username.'\'';
                echo $req_suppression_existantes."\n";
DmClient::get_query_results_from_dm_server($req_suppression_existantes, 'db_edgecreator');

                $intervalles=explode(';',$intervalle);
                foreach($intervalles as $intervalle) {
                    [$numero_debut, $numero_fin] = explode('~', $intervalle);
                    $req_ajout_nouvel_intervalle='INSERT INTO edgecreator_intervalles (ID_Valeur,Numero_debut,Numero_fin,username) '
                                        .'VALUES ('.$id_valeur.',\''.$numero_debut.'\',\''.$numero_fin.'\',\''.$resultat->username.'\')';
                    echo $req_ajout_nouvel_intervalle."\n";
DmClient::get_query_results_from_dm_server($req_ajout_nouvel_intervalle, 'db_edgecreator');
                }
            }
		}
	}

	function getRGB($couleurs) {
		if (is_array($couleurs)) {
            return $couleurs;
        }
		else if (strpos($couleurs, ',')) {
            return explode(',', $couleurs);
        }
        else {
            return hex2rgb($couleurs);
        }
	}

	function getValeur($option_nom,$option_valeur) {
		$texte='';
        $option_valeur_groupe = $this->getOptionValeurGroupe($option_valeur);
        foreach($option_valeur_groupe as $valeur=>$intervalles) {
			usort($intervalles,'trier_intervalles');
			$contient_template= Fonction_executable::toTemplatedString($valeur,false);
			$propriete_champs=new ReflectionProperty($this->Nom_fonction, 'champs');
			$champs=$propriete_champs->getValue();
			$type_donnee=$champs[$option_nom];
			switch($type_donnee) {
				case 'couleur':
					if (strpos($valeur, ',')===false) {
                        $valeur = implode(',', hex2rgb($valeur));
                    }
					$texte.='<span style="border:1px solid black;background-color:rgb('.$valeur.')">&nbsp;&nbsp;&nbsp;</span>';

				break;
				case 'fichier_ou_texte':
					$texte.=($contient_template?'':'<img src="'.Image::get_chemin_relatif($valeur).'" width="25" />').'&nbsp;'.$valeur;
				break;
				default:
					if ($option_nom === 'Chaine') {
                        $texte .= '<div class="valeur">';
                    }
					$texte.=str_replace(' ','&nbsp;',  urldecode($valeur));
					switch($option_nom) {
						case 'Pos_x':case 'Pos_y':case 'Dimension_x':case 'Dimension_y':
							$texte.='&nbsp;mm';
						break;
					}
					if ($option_nom === 'Chaine') {
                        $texte .= '</div>';
                    }
				break;
			}
			$texte.='<span style="font-size:12px;"> ('.implode(' ; ',$intervalles).')</span>&nbsp;<br />';
		}
		return $texte;
	}

	function getValeurModifiable($option_nom,$option_valeur,$modif=true) {
        $option_valeur_groupe = $this->getOptionValeurGroupe($option_valeur);
        ob_start();
		foreach($option_valeur_groupe as $valeur=>$intervalles) {
			usort($intervalles,'trier_intervalles');
			$intervalles_short=$this->getIntervalleShort(implode(';',$intervalles));
			$contient_template= Fonction_executable::toTemplatedString($valeur,false);
			$id=$option_nom.'.'.$intervalles_short;
			$propriete_champs=new ReflectionProperty($this->Nom_fonction, 'champs');
			$champs=$propriete_champs->getValue();
			$type_donnee=$champs[$option_nom];
			?><div id="ligne_<?=$id?>-" name="<?=$option_nom?>" class="ligne_option_intervalle"><table border="0"><tr><td style="width:30px"></td><td class="cellule_valeur largeur_standard"><?php
			switch($type_donnee) {
				case 'couleur':
					[$r, $g, $b] = $this->getRGB($valeur);
					?><input id="<?=$id?>-" class="parametre color" value="<?=rgb2hex($r,$g,$b)?>" /><?php
				break;
				case 'fichier_ou_texte':
					?><span id="<?=$id?>-alt-affichage1" class="<?=($contient_template?'cache':'montre')?>">
						<table cellspacing="0"><tr><td style="width:29px;padding-right:0">
						<img id="<?=$id?>-image" src="<?=Image::get_chemin_relatif($valeur)?>" width="25" />&nbsp;
						</td><td>
						<select class="parametre liste image alt" id="<?=$id?>-"><?php
						$options=$this->get_liste($this->Nom_fonction,$option_nom);
						foreach($options as $option) {
							?><option value="<?=$option?>" <?=(($option == $valeur) ? 'selected="selected"' :'')?>><?=$option?></option><?php
						}
						?></select>
						</td></tr></table>
					</span>
					<span class="<?=($contient_template?'montre':'cache')?>" id="<?=$id?>-alt-affichage2">
						<input class="parametre modifiable alt" id="<?=$id?>-" type="text" value="<?=($valeur)?>" />
					<br /></span> ou <a href="javascript:void(0)" id="<?=$id?>-alt" onclick="alterner_champ(this)">
						<span class="<?=($contient_template?'cache':'montre')?>" id="<?=$id?>-alt1">texte libre</span>
						<span class="<?=($contient_template?'montre':'cache')?>" id="<?=$id?>-alt2">fichier pr&eacute;d&eacute;fini</span>
					</a><?php
				break;
				case 'quantite':
					?><input class="parametre modifiable quantite" id="<?=$id?>-" type="text" value="<?=$valeur?>" /><?php
				break;
				case 'liste':
					?><select class="parametre liste" id="<?=$id?>-"><?php
					$options=$this->get_liste($this->Nom_fonction,$option_nom);
					foreach($options as $option) {
						?><option value="<?=$option?>" <?=($option == $valeur ?'selected="selected"':'')?>><?=$option?></option><?php
					}
					?></select><?php
				break;
				default:
					?><input class="parametre modifiable" id="<?=$id?>-" type="text" value="<?=($valeur)?>" /><?php
			}
			?>
			</td><td style="width:30px"></td><td class="cellule_intervalle_validite"><div class="intervalle_validite" name="<?=$option_nom?>"><?=$this->getIntervalleListesDeroulantes($option_nom,$intervalles_short,$modif)?></div>
			</td><td><a class="cloner" href="javascript:void(0)" onclick="cloner(this)">Cl</a>
			</td><td>|</td><td><a class="supprimer" href="javascript:void(0)" onclick="supprimer(this)">X</a></td>
			</tr></table></div><?php
		}?>
		<a href="javascript:void(0)" onclick="par_defaut('<?=$option_nom?>')">Renseigner la valeur par d&eacute;faut...</a><?php
        return ob_get_clean();
	}

	function  __toString() {
		$texte_intervalle=str_replace(';N',' ; N',$this->getIntervalle($this->Numero_debut,$this->Numero_fin));
		if (!isset($this->Option_nom) || is_null($this->Option_nom)) {
			return $this->Nom_fonction.' ('.$texte_intervalle.')';
		}

        return '<tr><td>'.$this->Option_nom.'</td>'
                  .'<td>'.$this->getValeur().'</td>'
                  .'<td>'.$texte_intervalle.'</td></tr>';
    }

	function ajouterNumeroAIntervalle($intervalle,$numero,$forcer_ajout=false) {
		$intervalles=explode(';',$intervalle);
		$numero_ajoute=false;
		foreach ($intervalles as $i=>$intervalle) {
			if (strpos($intervalle,'~') === false) {
                [$numero_debut, $numero_fin] = [$intervalle, $intervalle];
            }
			else {
                [$numero_debut, $numero_fin] = explode('~', $intervalle);
            }
			if (is_null($numero_fin)) {
                $numero_fin = $numero_debut;
            }
			[$nouveau_numero_est_apres_debut, $nouveau_numero_est_adjacent_debut] = $this->getPositionRelativeNumero($numero, $numero_debut);
			[$nouveau_numero_est_apres_fin, $nouveau_numero_est_adjacent_fin] = $this->getPositionRelativeNumero($numero, $numero_fin);
			if ($forcer_ajout) {
				$intervalles[]=$numero.'~'.$numero;
				$numero_ajoute=true;
				break;
			}
			if (!$nouveau_numero_est_apres_debut && $nouveau_numero_est_adjacent_debut) {
				$numero_debut=$numero;
				$numero_ajoute=true;
			}
			elseif ($nouveau_numero_est_apres_fin && $nouveau_numero_est_adjacent_fin) {
				$numero_fin=$numero;
				$numero_ajoute=true;
			}
			$intervalles[$i]=$numero_debut.'~'.$numero_fin;
		}
		$intervalles=implode(';',$intervalles);
		if (!$numero_ajoute) {
            $intervalles = $this->ajouterNumeroAIntervalle($intervalles, $numero, true);
        }
		return $intervalles;
	}

	function getPositionRelativeNumero($nouveau_numero,$numero) {
		$nouveau_numero_est_apres=null;
		$nouveau_numero_est_adjacent=null;
		$index_numero_trouve=-1;
		$index_nouveau_numero_trouve=-1;
		$i=0;
		foreach(self::$numeros_dispos as $numero_disponible) {
			if ($numero_disponible==$numero) {
				$index_numero_trouve=$i;
				$nouveau_numero_est_apres= $index_nouveau_numero_trouve == -1;
			}
			if ($numero_disponible==$nouveau_numero) {
				$index_nouveau_numero_trouve=$i;
			}
			if ($index_nouveau_numero_trouve != -1 && $index_numero_trouve !=-1) {
				$nouveau_numero_est_adjacent=abs($index_numero_trouve - $i) == 1;
				break;
			}
			$i++;
		}
		return [$nouveau_numero_est_apres,$nouveau_numero_est_adjacent];
	}

	function getIntervalleShort($intervalle) {
		if (strpos($intervalle, 'Tous')!==false) {
            return 'Tous~Tous';
        }
		return str_replace([' &agrave; ', 'Num&eacute;ro ', 'Num&eacute;ros '], array('~', '', ''), $intervalle);
	}

    function getIntervalle($numero_debut,$numero_fin) {
		$intervalles=[];
		$numeros_debut=explode(';',$numero_debut);
		$numeros_fin=explode(';',$numero_fin);
		foreach($numeros_debut as $i=>$numero_debut) {
			$numero_fin=$numeros_fin[$i];
			switch($numero_debut) {
                case 'Tous': $intervalles[]= 'Tous num&eacute;ros'; break;
                case $numero_fin: $intervalles[]= 'Num&eacute;ro '.$numero_debut; break;
                default: $intervalles[]= 'Num&eacute;ros '.$numero_debut.' &agrave; '.$numero_fin; break;
            }
		}
		return implode(';', $intervalles);
	}

	function getIntervalleListesDeroulantes($option_nom,$intervalle=null,$modif=true) {
		$ci = get_instance();
		$ci->load->helper('form');
		if (strpos($intervalle,'&agrave;')!==false) {
            $intervalle = $this->getIntervalleShort($intervalle);
        }
		if ($modif) {
			$intervalles=explode(';',$intervalle);
			foreach($intervalles as $i=>$sous_intervalle) {
                if (strpos($sous_intervalle, '~') === false) {
                    $intervalles[$i] .= '~' . $intervalles[$i];
                }
            }
			$intervalle=implode(';',$intervalles);
		}
		[$numero_debut_intervalle, $numero_fin_intervalle] = getNumerosDebutFinShort($intervalle);
		if ($modif) {
			$numeros_debut=explode(';',$numero_debut_intervalle);
			$numeros_fin=explode(';',$numero_fin_intervalle);
		}
		else {
			$numeros_debut=explode(';',self::$numero_debut);
			$numeros_fin=explode(';',self::$numero_fin);
		}
		$numeros_debut2= [];
		$numeros_fin2= [];
		foreach($numeros_debut as $i=>$numero_debut) {
			if (strpos($numero_debut,'~')!==false) {
				list($numeros_debut2[],$numeros_fin2[])=explode('~',$numero_debut);
			}
			else {
                list($numeros_debut2[], $numeros_fin2[]) = [$numero_debut, $numeros_fin[$i]];
            }
		}
		$numeros_debut=$numeros_debut2;
		$numeros_fin=$numeros_fin2;
		$texte='';
		foreach($numeros_debut as $i=>$numero_debut) {
			$numero_fin=$numeros_fin[$i];
			$id_debut=$option_nom.'.'.$this->getIntervalleShort($intervalle).'-numero-debut-intervalle'.$i.'-';
			$id_fin=$option_nom.'.'.$this->getIntervalleShort($intervalle).'-numero-fin-intervalle'.$i.'-';

			$texte.='<div><a href="javascript:void(0)" onclick="ajouter_intervalle(this)">Cl</a>|<a href="javascript:void(0)" onclick="supprimer_intervalle(this)">X</a>&nbsp;'
				  .'Num&eacute;ros '.form_dropdown('', self::$numeros_dispos, $numero_debut,'id="'.$id_debut.'" class="debut"')
				  .'&nbsp;&agrave;&nbsp; '.form_dropdown('', self::$numeros_dispos, $numero_fin,'id="'.$id_fin.'" class="fin"').'</div>';
		}
		return $texte;
	}

	function setPays($pays) {
		self::$pays=$pays;
	}

	function setMagazine($magazine) {
		self::$magazine=$magazine;
	}

	function setUsername($username) {
		self::$username=$username;
	}

	function setRandomId($random_id) {
		self::$random_id=$random_id;
	}

	function setNumeroDebut($numero_debut) {
		self::$numero_debut=$numero_debut;
	}

	function setNumeroFin($numero_fin) {
		self::$numero_fin=$numero_fin;
	}

	function setNumerosDisponibles($numeros_disponibles) {
		self::$numeros_dispos=$numeros_disponibles;
	}

	function setDropdownNumeros($numeros_disponibles) {
		self::$dropdown_numeros=$numeros_disponibles;
	}

	function setDropdownNumerosId($id,$dropdown='static') {
		if ($dropdown=='static') {
            $dropdown = self::$dropdown_numeros;
        }
		return str_replace('<select', '<select id="'.$id.'" ', $dropdown);
	}

	function setDropdownNumerosSelected($value,$dropdown='static') {
		if ($dropdown=='static') {
            $dropdown = self::$dropdown_numeros;
        }
		return str_replace('<option value="'.$value.'"', '<option value="'.$value.'" selected="selected" ', $dropdown);
	}

	function get_liste($type,$arg=null,$arg2=null) {
		$liste= [];
		switch($type) {
			case 'Police':
				$dir = opendir(self::getCheminPolices());
				while ($f = readdir($dir)) {
					if (strpos($f,'.ttf')===false) {
                        continue;
                    }
					if(is_file(self::getCheminPolices().$f)) {
						$nom=substr($f,0,strlen($f)-strlen('.ttf'));
						$liste[$nom]=$nom;
					}
				}
			 break;
			case 'Source':
			case 'Photos':
				$pays=$arg;
				$magazine=$arg2;
				switch($type) {
					case 'Source':
						$rep=Fonction_executable::getCheminElements($pays).'/';
						$extensions= ['png'];
					break;
					case 'Photos':
						$rep=Fonction_executable::getCheminPhotos($pays).'/';
						$extensions= ['jpg','jpeg','png'];
					break;
					default:
						return [];
				}
				if (($dir = @opendir($rep)) === false) { // Sans doute un nouveau pays, on crée le sous-dossier
					if (@opendir(preg_replace('#[^/]+/[^/]+/$#','',$rep))) {
						if (!@mkdir($rep,0777,true)) {
							$liste['erreur']=$rep;
						}

					}
					else {
						$liste['erreur']=$rep;
					}
				}
				else {
					while ($f = readdir($dir)) {
						if (strpos($f,$magazine.'.') !== 0 || !is_file($rep.$f)) {
                            continue;
                        }
						foreach($extensions as $extension) {
							if (strpos($f,'.'.$extension)===false) {
                                continue;
                            }
						}
						$liste[]=utf8_encode($f);
					}
				}
			break;
			case 'Source_photo':
				$pays=$arg;
				$magazine=$arg2;
				$rep=Fonction_executable::getCheminPhotos($pays).'/';
				$dir = opendir($rep);
				while ($f = readdir($dir)) {
					if ((strpos($f,'.png')===false
					  && strpos($f,'.jpg')===false )
					 || strpos($f, $magazine.'.') !== 0) {
                        continue;
                    }
					if(is_file($rep.$f)) {
						$nom=$f;
						$liste[]=utf8_encode($nom);
					}
				}
			 break;
			case 'Position':
				$liste['bas']='bas';
				$liste['haut']='haut';
			 break;
			case 'Demi_hauteur':case 'Rempli':case 'Mesure_depuis_haut':
				$liste['Oui']='Oui';
				$liste['Non']='Non';
			 break;
			case 'Sens':
				$liste['Horizontal']='Horizontal';
				$liste['Vertical']='Vertical';
			 break;
			case 'Utilisateurs':

                $requete_utilisateurs='SELECT ID, username FROM users ORDER BY username';
                $resultats_utilisateurs=$this->requete_select_dm($requete_utilisateurs);
                $usernames = [];
                foreach($resultats_utilisateurs as $resultat_utilisateur) {
                    $username = utf8_encode($resultat_utilisateur['username']);

                    $usernames[$resultat_utilisateur['ID']]=$username;
                    $liste[$username]='';
                }

                $id_modele = $this->session->userdata('id_modele');
                $contributeurs = DmClient::get_service_results_ec(DmClient::$dm_server, 'GET', "/edgecreator/contributors/$id_modele");

                foreach($contributeurs as $contributeur) {
                    $liste[$usernames[$contributeur->idUtilisateur]].=$contributeur->contribution;
                }
			 break;
			case 'Fonctions':
				foreach(self::$noms_fonctions as $nom) {
					$liste[$nom]=$nom::$libelle;
				}
			 break;
		}

        switch($type) {
            case 'Police':
            case 'Source':
            case 'Source_photo':
            case 'Photos':
                sort($liste);
            break;
            case 'Fonctions':
                asort($liste);
            break;
            default:
			    uksort($liste, 'strnatcasecmp');
            break;
		}
		return $liste;
	}

	static function rendu_image($save) {
		if (Viewer_wizard::$is_debug===false) {
            header('Content-type: image/png');
        }
		imagepng(Viewer_wizard::$image);

		if ($save) {
            $dossier_image= self::getCheminImages().'/'.Viewer_wizard::$pays.'/tmp/';
            @rmdir($dossier_image);
            @mkdir($dossier_image);
            $nom_image=$dossier_image.Viewer_wizard::$random_id.'.png';
            imagepng(Viewer_wizard::$image,$nom_image);
        }
    }

    static function save_image($pays, $magazine, $numero, $image) {
        $dossier_pays = self::getCheminImages().$pays;
        $dossier_gen = $dossier_pays.'/gen';
        if (!is_dir($dossier_pays)) {
            @mkdir($dossier_pays);
        }
        if (!is_dir($dossier_gen)) {
            @mkdir($dossier_gen);
        }

        $chemin_image = $dossier_gen.'/'.$magazine.'.'.$numero.'.png';
        if (!is_file($chemin_image)) {
            imagepng($image,$chemin_image);
        }

        header('Content-type: image/png');
        imagepng($image);
    }

    function defaut($pays, $magazine, $numero, $zoom, $largeur, $hauteur) {
        $image=imagecreatetruecolor($largeur,$hauteur);
        $blanc=imagecolorallocate($image,255,255,255);
        $noir = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $largeur-2, $hauteur-2, $blanc);
        imagettftext($image,$largeur/2,90,$largeur*7/10,$hauteur-$largeur*4/5,
            $noir, self::getCheminPolices() . 'Arial.ttf',$pays.'/'.$magazine.' '.$numero);

        $noir=imagecolorallocate($image, 0, 0, 0);
        for ($i=0; $i<.15* $zoom; $i++) {
            imagerectangle($image, $i, $i, $largeur - 1 - $i, $hauteur - 1 - $i, $noir);
        }
        $gris_250=imagecolorallocate($image, 250,250,250);
        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }
        imagefilledrectangle($image, $largeur/4,$largeur/4, $largeur*3/4,$largeur*3/4,$gris_250);
        return $image;
    }

    /**
     * @param $option_valeur
     * @return array
     */
    private function getOptionValeurGroupe($option_valeur): array
    {
        if (!is_array($option_valeur)) { // Valeur par défaut
            $option_valeur = ['Tous' => $option_valeur];
        }
        asort($option_valeur);
        $option_valeur_groupe = [];
        foreach ($option_valeur as $intervalle => $valeur) {
            if (!array_key_exists($valeur, $option_valeur_groupe)) {
                $option_valeur_groupe[$valeur] = [];
            }
            $option_valeur_groupe[$valeur][] = $intervalle;
        }
        uasort($option_valeur_groupe, 'trier_intervalles');
        return $option_valeur_groupe;
    }
}

class Fonction extends Modele_tranche {
	public $options;
	static $valeurs_defaut= ['Remplir'=> ['Pos_x'=>0,'Pos_y'=>0]];
}

class Fonction_executable extends Fonction {

	static $descriptions= [];

	function __construct($options,$creation=false,$get_options_defaut=true) {
        parent::__construct();
		if (!is_object($options)) {
			$options=new CountableObject();
		}
		$this->options=$options;
		$classe=get_class($this);
		if ($creation) {
			$propriete_valeurs_nouveau=new ReflectionProperty($classe, 'valeurs_nouveau');
			$valeurs_nouveau=$propriete_valeurs_nouveau->getValue();
			foreach($valeurs_nouveau as $nom=>$valeur) {
				if (!isset($this->options->$nom)) {
					$this->options->$nom=$valeur;
				}
			}
		}
		else if ($get_options_defaut){
			$propriete_valeurs_defaut=new ReflectionProperty($classe, 'valeurs_defaut');
			$valeurs_defaut=$propriete_valeurs_defaut->getValue();
			foreach($valeurs_defaut as $nom=>$valeur) {
				if (!isset($this->options->$nom) || $this->options->$nom == []) {
					$this->options->$nom=$valeur;
				}
			}
			$propriete_champs=new ReflectionProperty($classe, 'champs');
			$champs=$propriete_champs->getValue();
			foreach(array_keys($champs) as $nom) {
				if (!isset($this->options->$nom)) {
                    $this->options->$nom = null;
                }
			}
			return;
		}
		else {
			$propriete_champs=new ReflectionProperty($classe, 'champs');
			$valeurs_champs=$propriete_champs->getValue();
			foreach($valeurs_champs as $nom=>$valeur) {
				if (!isset($this->options->$nom)) {
                    $this->options->$nom = null;
                }
			}

			return;
		}

		if ($creation) {
			$propriete_champs=new ReflectionProperty($classe, 'champs');
			$champs=$propriete_champs->getValue();
			foreach(array_keys($champs) as $nom) {
				if (!isset($this->options->$nom) || (strpos('Couleur', $nom)!==false && $this->options->$nom== [])) {
					self::erreur('Le champ "'.$nom.'" est indéfini !');
				}
			}
		}
	}

	function getJSONOptions() {
		return json_encode($this->options);
	}
	static function erreur($erreur) {
		if (!is_resource(Viewer_wizard::$image)) {
			Viewer_wizard::$largeur=z(20);
			Viewer_wizard::$hauteur=z(220);
			Viewer_wizard::$image=imagecreatetruecolor(Viewer_wizard::$largeur, Viewer_wizard::$hauteur);
		}
		imagefilledrectangle(Viewer_wizard::$image, 0, 0, Viewer_wizard::$largeur, Viewer_wizard::$hauteur, imagecolorallocate(Viewer_wizard::$image, 255, 255, 255));
		$noir=imagecolorallocate(Viewer_wizard::$image,0,0,0);
		$lignes_erreur=explode(';', $erreur);
		foreach($lignes_erreur as $i=>$ligne) {
			if ($i === 0) {
                $texte_erreur = 'Erreur etape ' . Viewer_wizard::$etape_en_cours['num_etape'] . ' (Fonction ' . Viewer_wizard::$etape_en_cours['nom_fonction'] . ') : ' . $ligne;
            }
			else {
                $texte_erreur = $ligne;
            }
			imagettftext(Viewer_wizard::$image,z(3),90,
						 ($i+1)*Viewer_wizard::$largeur/3,Viewer_wizard::$hauteur,
						 $noir,Modele_tranche::getCheminPolices() . 'Arial.ttf',$texte_erreur);
		}
		Modele_tranche::rendu_image(false);
        ErrorHandler::error_log($erreur);
		exit();
	}

    static function getCheminPhotos($pays=null) {
		if (is_null($pays)) {
            $pays = self::$pays;
        }
		return Modele_tranche::getCheminImages() . $pays.'/photos';
	}

    static function getCheminPhotosTranchesMultiples() {
		return Modele_tranche::getCheminImages() .'tranches_multiples';
	}

	static function getCheminElements($pays=null) {
		if (is_null($pays)) {
            $pays = self::$pays;
        }
        return Modele_tranche::getCheminImages() . $pays.'/elements';
	}

	static function toTemplatedString($str,$actif=true) {
		$tab= [
            'numero'=>'#\[Numero\]#is',
				   'numero[]'=>'#\[Numero\[([0-9]+)\]\]#is',
				   'largeur'=>'#\[Largeur\]#is',
				   'hauteur'=>'#\[Hauteur\]#is',
				   'caracteres_speciaux'=>'#\Â°#is'
        ];
		if ($str === []) {
            $str = '';
        }
		foreach($tab as $nom=>$regex) {
			if (0 !== preg_match($regex, $str, $matches)) {
				if (!$actif) {
                    return true;
                }
				switch($nom) {
					case 'numero':
						$str=preg_replace($regex, Viewer_wizard::$numero, $str);
					break;
					case 'numero[]':
						$spl=str_split(Viewer_wizard::$numero);
						if (0!=preg_match_all($regex, $str, $matches)) {
							foreach($matches[1] as $i=>$num_caractere) {
								if (!array_key_exists($num_caractere, $spl)) {
                                    $str = str_replace($matches[0][$i], '', $str);
                                }
								else {
                                    $str = str_replace($matches[0][$i], preg_replace($regex, $spl[$num_caractere], $matches[0][$i]), $str);
                                }
							}
						}
					break;
					case 'largeur':
						$str=preg_replace($regex, Viewer_wizard::$largeur, $str);
						eval('$str=' .$str. ';');
						$str/=z(1);
					break;
					case 'hauteur':
						$str=preg_replace($regex, Viewer_wizard::$hauteur, $str);
						eval('$str=' .$str. ';');
						$str/=z(1);
					break;
					case 'caracteres_speciaux':
						$str=str_replace('Â°','°',$str);
					break;

				}
			}
		}
		if (!$actif) {
            return true;
        }
		return $str;
	}

}

class Dimensions extends Fonction_executable {
	static $champs= ['Dimension_x'=>'quantite','Dimension_y'=>'quantite'];
	static $valeurs_nouveau= ['Dimension_x'=>15,'Dimension_y'=>200];
	static $valeurs_defaut= [];
	static $descriptions= [
        'Dimension_x'=>'Largeur de la tranche',
							   'Dimension_y'=>'Hauteur de la tranche'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->verifier_erreurs();
		Viewer_wizard::$image=imagecreatetruecolor(z($this->options->Dimension_x), z($this->options->Dimension_y));
		Viewer_wizard::$largeur=z($this->options->Dimension_x);
		Viewer_wizard::$hauteur=z($this->options->Dimension_y);
		imagefill(Viewer_wizard::$image,0,0,  imagecolorallocatealpha(Viewer_wizard::$image, 255, 255, 255, 127));
	}

	function verifier_erreurs() {
		if ($this->options->Dimension_x < 0 || $this->options->Dimension_y < 0 ) {
			self::erreur('Dimensions négatives');
		}
		if ($this->options->Dimension_x === [] || $this->options->Dimension_y === []) {
			self::erreur('Dimensions nulles');
		}
	}
}

class Remplir extends Fonction_executable {
	static $libelle='Remplir une zone avec une couleur';
	static $champs= ['Pos_x'=>'quantite','Pos_y'=>'quantite','Couleur'=>'couleur'];
	static $valeurs_nouveau= ['Pos_x'=>0,'Pos_y'=>0,'Couleur'=>'AAAAAA'];
	static $valeurs_defaut= ['Pos_x'=>0,'Pos_y'=>0];
	static $descriptions= [
        'Pos_x'=>'Abscisse du point de d&eacute;part du remplissage',
							   'Pos_y'=>'Ordonn&eacute;e du point de d&eacute;part du remplissage',
							   'Couleur'=>'Couleur de remplissage'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->options->Pos_x=z(self::toTemplatedString($this->options->Pos_x));
		$this->options->Pos_y=z(self::toTemplatedString($this->options->Pos_y));
		$this->verifier_erreurs();
		[$r, $g, $b] = $this->getRGB($this->options->Couleur);
		$couleur=imagecolorallocate(Viewer_wizard::$image, $r,$g,$b);
		imagefill(Viewer_wizard::$image, $this->options->Pos_x, $this->options->Pos_y, $couleur);
	}

	function verifier_erreurs() {
		if ($this->options->Pos_x >= Viewer_wizard::$largeur || $this->options->Pos_y >= Viewer_wizard::$hauteur
		 || $this->options->Pos_x < 0 || $this->options->Pos_y < 0) {
			self::erreur('Point de remplissage hors de l\'image : ('.$this->options->Pos_x.','.$this->options->Pos_y.') vers ('.Viewer_wizard::$largeur.','.Viewer_wizard::$hauteur.')');
		}
	}
}

class Image extends Fonction_executable {
	static $libelle='Ins&eacute;rer une image';
	static $champs= ['Source'=>'fichier_ou_texte','Decalage_x'=>'quantite','Decalage_y'=>'quantite','Compression_x'=>'quantite','Compression_y'=>'quantite','Position'=>'liste'];
	static $valeurs_nouveau= ['Source'=>'','Decalage_x'=>'5','Decalage_y'=>'5','Compression_x'=>'0.6','Compression_y'=>'0.6','Position'=>'haut'];
	static $valeurs_defaut= ['Decalage_x'=>0,'Decalage_y'=>0,'Compression_x'=>1,'Compression_y'=>1,'Position'=>'haut'];

	static $descriptions= [
        'Source'=>'Nom de l\'image',
							   'Decalage_x'=>'Marge gauche de l\'image',
							   'Decalage_y'=>'Marge haute de l\'image<br />(Par rapport au haut de l\'image si Position=haut, sinon par rapport au bas)',
							   'Compression_x'=>'Compression de la largeur de l\'image',
							   'Compression_y'=>'Compression de la hauteur de l\'image',
							   'Position'=>'Position de l\'image par rapport &agrave; la tranche : Haut ou Bas'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->options->Decalage_x=self::toTemplatedString($this->options->Decalage_x);
		$this->options->Decalage_y=self::toTemplatedString($this->options->Decalage_y);
		$this->options->Source=self::toTemplatedString($this->options->Source);
		$this->verifier_erreurs();

		$src = $this->options->Source;
		$extension_image=strtolower(substr($src, strrpos($src, '.')+1,strlen($src)-strrpos($src, '.')-1));
		$chemin_reel= self::get_chemin_reel($this->options->Source);
		switch ($extension_image) {
			case 'jpg':
				$sous_image=imagecreatefromjpeg($chemin_reel);
			break;
			case 'png':
				$sous_image=imagecreatefrompng($chemin_reel);
			break;
			default:
				return;
		}
		[$width, $height] = [imagesx($sous_image), imagesy($sous_image)];
		$hauteur_sous_image=Viewer_wizard::$largeur*($height/$width);
		if ($this->options->Position === 'bas') {
			$this->options->Decalage_y=Viewer_wizard::$hauteur-$hauteur_sous_image-z($this->options->Decalage_y);
		}
		else {
            $this->options->Decalage_y = z($this->options->Decalage_y);
        }
		imagecopyresampled (Viewer_wizard::$image, $sous_image, z($this->options->Decalage_x), $this->options->Decalage_y, 0, 0, Viewer_wizard::$largeur*$this->options->Compression_x, $hauteur_sous_image*$this->options->Compression_y, $width, $height);
	}

	static function get_chemin_reel($source) {
		return (strpos($source, 'images_myfonts')!==false) ?
				 $source
			   : self::getCheminElements().'/'.$source;
	}

	static function get_chemin_relatif($source) {
		return Modele_tranche::getCheminImages() . self::$pays.'/elements/'.$source;
	}

	function verifier_erreurs() {
		if (empty($this->options->Source)) {
			self::erreur('Le fichier n\'a pas été défini');
		}
		$chemin_reel= self::get_chemin_reel($this->options->Source);
		if (!is_file($chemin_reel)) {
			self::erreur('Le fichier '.$this->options->Source.' n\'existe pas');
		}
	}
}

class TexteMyFonts extends Fonction_executable {
	static $libelle='Ajouter du texte';
	static $champs= ['URL'=>'texte','Couleur_texte'=>'couleur','Couleur_fond'=>'couleur','Largeur'=>'quantite','Chaine'=>'texte','Pos_x'=>'quantite','Pos_y'=>'quantite','Compression_x'=>'quantite','Compression_y'=>'quantite','Rotation'=>'quantite','Demi_hauteur'=>'liste','Mesure_depuis_haut'=>'liste'];
	static $valeurs_nouveau= ['URL'=>'redrooster.block-gothic-rr.demi-extra-condensed','Couleur_texte'=>'000000','Couleur_fond'=>'ffffff','Largeur'=>'700','Chaine'=>'Le journal de Mickey','Pos_x'=>'0','Pos_y'=>'5','Compression_x'=>'0.3','Compression_y'=>'0.3','Rotation'=>'90','Demi_hauteur'=>'Oui','Mesure_depuis_haut'=>'Oui'];
	static $valeurs_defaut= ['Rotation'=>0,'Compression_x'=>'1','Compression_y'=>'1','Mesure_depuis_haut'=>'Oui'];

	static $descriptions= [
        'URL'=>'Nom de la police',
							   'Couleur_texte'=>'Couleur du texte',
							   'Couleur_fond'=>'Couleur de l\'arri&egrave;re-plan du texte',
							   'Largeur'=>'Largeur occup&eacute; par le texte',
							   'Chaine'=>'Cha&icirc;ne de caract&egrave;res du texte',
							   'Pos_x'=>'Marge de l\'image depuis la gauche de la tranche',
							   'Pos_y'=>'Marge de l\'image depuis le haut de la tranche',
							   'Compression_x'=>'Compression de la largeur du texte<br />(1 = Pas de compression)',
							   'Compression_y'=>'Compression de la hauteur du texte<br />(1 = Pas de compression)',
							   'Rotation'=>'Rotation du texte<br />(0 = Pas de rotation)',
							   'Demi_hauteur'=>'S&eacute;lectionnez "Oui" si jamais vous ne voyez le texte que sur la moiti&eacute; de sa hauteur',
							   'Mesure_depuis_haut'=>'"Oui" si Pos_y doit repr&eacute;senter la marge jusqu\'au haut du texte, "Non" s\'il s\'agit de la marge jusqu\'au bas du texte'
    ];

	function __construct($options, $executer = true, $creation = false, $get_options_defaut = true, $options_avancees = true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }

		$this->options->Chaine=self::toTemplatedString($this->options->Chaine);
		if ($this->options->Chaine === ' ') {
            return;
        }
		$this->options->URL=str_replace('.','/',$this->options->URL);
		$this->verifier_erreurs();
		[$r, $g, $b] = $this->getRGB($this->options->Couleur_fond);
		[$r_texte, $g_texte, $b_texte] = $this->getRGB($this->options->Couleur_texte);

		$this->options->Couleur_fond=rgb2hex($r, $g, $b);
		$this->options->Couleur_texte=rgb2hex($r_texte,$g_texte,$b_texte);

		$ci =& get_instance();
		$ci->load->model('Myfonts');
		$myFonts=new Myfonts(
            $this->options->URL,
            $this->options->Couleur_texte,
            $this->options->Couleur_fond,
            $this->options->Largeur,
            $this->options->Chaine,
            Viewer_wizard::$largeur / 2 // Précision
        );
		$texte=$myFonts->im;
		if ($this->options->Demi_hauteur === 'Oui') {
			$width=imagesx($texte);
			$height=imagesy($texte);
			$texte2=imagecreatetruecolor ($width, $height/2);
			imagefill($texte2,0,0,imagecolorallocate($texte2,$r,$g,$b));
			imagecopyresampled($texte2, $texte, 0, 0, 0, 0, $width, $height/2, $width, $height/2);
			$texte=$texte2;
		}

		$fond=imagecolorallocatealpha($texte, $r, $g, $b, 127);
		imagefill($texte,0,0,$fond);

		if (!is_null($this->options->Rotation)) {
			$texte=imagerotate($texte, $this->options->Rotation, $fond);
		}

		if ($options_avancees) {
			$this->options->Pos_x=self::toTemplatedString($this->options->Pos_x);
			$this->options->Pos_y=self::toTemplatedString($this->options->Pos_y);

			$width=imagesx($texte);
			$height=imagesy($texte);
			$nouvelle_largeur=Viewer_wizard::$largeur*$this->options->Compression_x;
			$nouvelle_hauteur=Viewer_wizard::$largeur*($height/$width)*$this->options->Compression_y;
			if ($this->options->Mesure_depuis_haut === 'Non') {
                $this->options->Pos_y -= $nouvelle_hauteur / z(1);
            }
			imagecopyresampled (Viewer_wizard::$image, $texte, z($this->options->Pos_x), z($this->options->Pos_y), 0, 0, $nouvelle_largeur, $nouvelle_hauteur, $width, $height);
		}
		else {
            Viewer_wizard::$image = $texte;
        }

	}

	function verifier_erreurs() {
		if (is_array($this->options->Couleur_fond) && count($this->options->Couleur_fond) === 0) {
            self::erreur('Couleur de fond indéfinie');
        }
		if (is_array($this->options->Couleur_texte) && count($this->options->Couleur_texte) === 0) {
            self::erreur('Couleur de texte indéfinie');
        }
	}
}

class TexteTTF extends Fonction_executable {
	static $libelle='Ajouter du texte';
	static $champs= ['Pos_x'=>'quantite','Pos_y'=>'quantite','Rotation'=>'quantite','Taille'=>'quantite','Couleur'=>'couleur','Chaine'=>'texte','Police'=>'liste','Compression_x'=>'quantite','Compression_y'=>'quantite'];
	static $valeurs_nouveau= ['Pos_x'=>'3','Pos_y'=>'5','Rotation'=>'-90','Taille'=>'3.5','Couleur'=>'F50D05','Chaine'=>'Texte du num&eacute;ro [Numero]','Police'=>'Arial','Compression_x'=>'1','Compression_y'=>'1'];
	static $valeurs_defaut= ['Pos_x'=>0,'Pos_y'=>0,'Rotation'=>0,'Compression_x'=>'1','Compression_y'=>'1'];


	static $descriptions= [
        'Pos_x'=>'Marge du texte depuis la gauche de la tranche',
							   'Pos_y'=>'Marge du texte depuis le haut de la tranche',
							   'Rotation'=>'Rotation du texte<br />(0 = Pas de rotation)',
							   'Taille'=>'Taille du texte, en pt',
							   'Couleur'=>'Couleur du texte',
							   'Chaine'=>'Cha&icirc;ne de caract&egrave;res du texte',
							   'Police'=>'Nom de la police de caract&egrave;res',
							   'Compression_x'=>'Compression de la largeur de l\'image<br />(1 = Pas de compression)',
							   'Compression_y'=>'Compression de la hauteur de l\'image<br />(1 = Pas de compression)'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->options->Chaine=self::toTemplatedString($this->options->Chaine);
		[$r, $g, $b] = $this->getRGB($this->options->Couleur);
		$couleur_texte=imagecolorallocate(Viewer_wizard::$image, $r,$g,$b);

		$centrage_auto_x=$this->options->Pos_x == -1;
		$centrage_auto_y=$this->options->Pos_y == -1;
		$p=calculateTextBox($this->options->Chaine, Modele_tranche::getCheminPolices().$this->options->Police.'.ttf', z($this->options->Taille), $this->options->Rotation);
		if ($centrage_auto_x || $centrage_auto_y) {
			if ($centrage_auto_x) {
                $this->options->Pos_x = (Viewer_wizard::$largeur - $p['width'] * $this->options->Compression_x) / z(2);
            }
			if ($centrage_auto_y) {
                $this->options->Pos_y = (Viewer_wizard::$hauteur - $p['height'] * $this->options->Compression_y) / z(2);
            }
		}
		if ($this->options->Compression_x != 1 || $this->options->Compression_y != 1) {
			$largeur_tmp=max($p['width'],Viewer_wizard::$largeur)+z(1);
			$hauteur_tmp=max($p['height'],Viewer_wizard::$hauteur)+z(1);
			$image2=imagecreatetruecolor($largeur_tmp,$hauteur_tmp);
			imagefill($image2, 0,0, imagecolorallocatealpha($image2, 255, 255, 255, 127));
			if ($this->options->Rotation > 45 && $this->options->Rotation <135) {
				$pos_x_tmp=$p['left'];
				$pos_y_tmp=$p['top'];
			}
			else {
                $pos_x_tmp = 0;
                $pos_y_tmp = 0;
			}

			imagettftext($image2,z($this->options->Taille),$this->options->Rotation,
						 $pos_x_tmp,$pos_y_tmp,
						 $couleur_texte,Modele_tranche::getCheminPolices().$this->options->Police.'.ttf',$this->options->Chaine);
			imagepng($image2, Modele_tranche::getCheminImages() . 'tmp/ttfcomp.png');

			imagecopyresampled(Viewer_wizard::$image, $image2, z($this->options->Pos_x)*(Viewer_wizard::$largeur/$largeur_tmp), z($this->options->Pos_y)*(Viewer_wizard::$hauteur/$hauteur_tmp), 0,0, Viewer_wizard::$largeur*$this->options->Compression_x, Viewer_wizard::$hauteur*$this->options->Compression_y, $largeur_tmp, $hauteur_tmp);

		}
		else {
			imagettftext(Viewer_wizard::$image,z($this->options->Taille),$this->options->Rotation,
						 z($this->options->Pos_x),z($this->options->Pos_y),
						 $couleur_texte,Modele_tranche::getCheminPolices().$this->options->Police.'.ttf',$this->options->Chaine);
		}
	}
}

class Polygone extends Fonction_executable {
	static $libelle='Dessiner un polygone';
	static $champs= ['X'=>'texte','Y'=>'texte','Couleur'=>'couleur'];
	static $valeurs_nouveau= ['X'=>'1,4,7,14','Y'=>'5,25,14,12','Couleur'=>'000000'];
	static $valeurs_defaut= [];

	static $descriptions= [
        'X'=>'Liste des abscisses des points, s&eacute;par&eacute;es par virgules',
							   'Y'=>'Liste des ordonn&eacute;es des points, s&eacute;par&eacute;es par virgules',
							   'Couleur'=>'Couleur du polygone'
    ];


	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->options->X=explode(',',str_replace(' ','',$this->options->X));
		$this->options->Y=explode(',',str_replace(' ','',$this->options->Y));
		$args= [Viewer_wizard::$image];
		$coord= [];
		foreach(array_keys($this->options->X) as $i) {
			$this->options->X[$i]=self::toTemplatedString($this->options->X[$i]);
			$this->options->Y[$i]=self::toTemplatedString($this->options->Y[$i]);
			$coord[]=z($this->options->X[$i]);
			$coord[]=z($this->options->Y[$i]);
		}
		$args[]=$coord;
		$args[]=count($this->options->X);
		[$r, $g, $b] = $this->getRGB($this->options->Couleur);
		$args[]=imagecolorallocate(Viewer_wizard::$image, $r,$g,$b);
		imagefilledpolygon(...$args);

	}
}

class Agrafer extends Fonction_executable {
	static $libelle='Agrafer la tranche';
	static $champs= ['Y1'=>'quantite','Y2'=>'quantite','Taille_agrafe'=>'quantite'];
	static $valeurs_nouveau= ['Y1'=>'[Hauteur]*0.2','Y2'=>'[Hauteur]*0.8','Taille_agrafe'=>'[Hauteur]*0.05'];
	static $valeurs_defaut= ['Y1'=>'[Hauteur]*0.2','Y2'=>'[Hauteur]*0.8','Taille_agrafe'=>'[Hauteur]*0.05'];

	static $descriptions= [
        'Y1'=>'Marge de la 1&egrave;re agrafe par rapport au haut de la tranche',
							   'Y2'=>'Marge de la 2&egrave;me agrafe par rapport au haut de la tranche',
							   'Taille_agrafe'=>'Hauteur de chaque agrafe'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->options->Y1=self::toTemplatedString($this->options->Y1);
		$this->options->Y2=self::toTemplatedString($this->options->Y2);
		$this->options->Taille_agrafe=self::toTemplatedString($this->options->Taille_agrafe);
		$noir=imagecolorallocate(Viewer_wizard::$image, 0, 0, 0);
		imagefilledrectangle(Viewer_wizard::$image, Viewer_wizard::$largeur/2 -z(.25), z($this->options->Y1), Viewer_wizard::$largeur/2 +z(.25), z($this->options->Y1+$this->options->Taille_agrafe), $noir);
		imagefilledrectangle(Viewer_wizard::$image, Viewer_wizard::$largeur/2 -z(.25), z($this->options->Y2), Viewer_wizard::$largeur/2 +z(.25), z($this->options->Y2+$this->options->Taille_agrafe), $noir);
	}
}

class Degrade extends Fonction_executable {
	static $libelle='Remplir une zone avec un d&eacute;grad&eacute;';
	static $champs= ['Couleur_debut'=>'couleur','Couleur_fin'=>'couleur','Sens'=>'liste','Pos_x_debut'=>'quantite','Pos_x_fin'=>'quantite','Pos_y_debut'=>'quantite','Pos_y_fin'=>'quantite'];
	static $valeurs_nouveau= ['Couleur_debut'=>'D01721','Couleur_fin'=>'0000FF','Sens'=>'Vertical','Pos_x_debut'=>'3','Pos_x_fin'=>'[Largeur]-3','Pos_y_debut'=>'3','Pos_y_fin'=>'[Hauteur]*0.5'];
	static $valeurs_defaut= [];

	static $descriptions= [
        'Couleur_debut'=>'Couleur du d&eacute;but du d&eacute;grad&eacute;',
							   'Couleur_fin'=>'Couleur du fin du d&eacute;grad&eacute;',
							   'Sens'=>'"Horizontal" (de gauche &agrave; droite) ou "Vertical" (de haut en bas)',
							   'Pos_x_debut'=>'Marge du d&eacute;but du d&eacute;grad&eacute; par rapport &agrave; la gauche de la tranche',
							   'Pos_x_fin'=>'Marge de la fin du d&eacute;grad&eacute; par rapport &agrave; la gauche de la tranche',
							   'Pos_y_debut'=>'Marge du d&eacute;but du d&eacute;grad&eacute; par rapport au haut de la tranche',
							   'Pos_y_fin'=>'Marge de la fin du d&eacute;grad&eacute; par rapport au haut de la tranche'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }

		$this->options->Pos_x_debut=z(self::toTemplatedString($this->options->Pos_x_debut));
		$this->options->Pos_x_fin=z(self::toTemplatedString($this->options->Pos_x_fin));
		$this->options->Pos_y_debut=z(self::toTemplatedString($this->options->Pos_y_debut));
		$this->options->Pos_y_fin=z(self::toTemplatedString($this->options->Pos_y_fin));
		[$r1, $g1, $b1] = $this->getRGB($this->options->Couleur_debut);
		[$r2, $g2, $b2] = $this->getRGB($this->options->Couleur_fin);
		$couleur1= [$r1,$g1,$b1];
		$couleur2= [$r2,$g2,$b2];
		if ($this->options->Sens === 'Horizontal') {
			if ($this->options->Pos_x_debut < $this->options->Pos_x_fin) {
				$couleurs_inter=self::getMidColors($couleur1, $couleur2, abs($this->options->Pos_x_debut-$this->options->Pos_x_fin));
				foreach($couleurs_inter as $i => [$rouge_inter, $vert_inter, $bleu_inter]) {
					$couleur_allouee=imagecolorallocate(Viewer_wizard::$image, $rouge_inter,$vert_inter,$bleu_inter);
					imageline(Viewer_wizard::$image, $this->options->Pos_x_debut +$i, $this->options->Pos_y_debut, $this->options->Pos_x_debut +$i, $this->options->Pos_y_fin, $couleur_allouee);
				}
			}
			else {
				$couleurs_inter=self::getMidColors($couleur1, $couleur2, abs($this->options->Pos_y_debut-$this->options->Pos_y_fin));
				foreach($couleurs_inter as $i => [$rouge_inter, $vert_inter, $bleu_inter]) {
					$couleur_allouee=imagecolorallocate(Viewer_wizard::$image, $rouge_inter,$vert_inter,$bleu_inter);
					imageline(Viewer_wizard::$image, $this->options->Pos_x_debut -$i, $this->options->Pos_y_debut, $this->options->Pos_y_fin -$i, $this->options->Pos_y_debut, $couleur_allouee);
				}
			}
		}
		else {
			$couleurs_inter=self::getMidColors($couleur1, $couleur2, abs($this->options->Pos_y_debut-$this->options->Pos_y_fin));
			if ($this->options->Pos_y_debut < $this->options->Pos_y_fin) {
				foreach($couleurs_inter as $i => [$rouge_inter, $vert_inter, $bleu_inter]) {
					$couleur_allouee=imagecolorallocate(Viewer_wizard::$image, $rouge_inter,$vert_inter,$bleu_inter);
					imageline(Viewer_wizard::$image, $this->options->Pos_x_debut, $this->options->Pos_y_debut +$i, $this->options->Pos_x_fin, $this->options->Pos_y_debut +$i, $couleur_allouee);
				}
			}
			else {
				foreach($couleurs_inter as $i => [$rouge_inter, $vert_inter, $bleu_inter]) {
					$couleur_allouee=imagecolorallocate(Viewer_wizard::$image, $rouge_inter,$vert_inter,$bleu_inter);
					imageline(Viewer_wizard::$image, $this->options->Pos_x_debut, $this->options->Pos_y_fin -$i, $this->options->Pos_x_fin, $this->options->Pos_y_fin -$i, $couleur_allouee);
				}
			}
		}
	}
	static function getMidColors($rgb1, $rgb2, $nb) {
		$rgb_mid= [];
		for ($j = 1; $j <= $nb; $j++) {
			$rgb_mid[$j]= [];
			for ($i = 0; $i < 3; $i++) {
				if ($rgb1[$i] < $rgb2[$i]) {
					$rgb_mid[$j][]= round(((max($rgb1[$i], $rgb2[$i]) - min($rgb1[$i], $rgb2[$i])) / ($nb + 1)) * $j + min($rgb1[$i], $rgb2[$i]));
				} else {
					$rgb_mid[$j][]= round(max($rgb1[$i], $rgb2[$i]) - ((max($rgb1[$i], $rgb2[$i]) - min($rgb1[$i], $rgb2[$i])) / ($nb + 1)) * $j);
				}
			}
		}
		return $rgb_mid;
	}
}

class DegradeTrancheAgrafee extends Fonction_executable {
	static $libelle='Remplir la tranche avec un d&eacute;grad&eacute; et l\'agrafer';
	static $champs= ['Couleur'=>'couleur'];
	static $valeurs_nouveau= ['Couleur'=>'D01721'];
	static $valeurs_defaut= [];

	static $descriptions= ['Couleur'=>'Couleur de la tranche'];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$coef_degrade=1.75;
		[$r1, $g1, $b1] = $this->getRGB($this->options->Couleur);
		[$r2, $g2, $b2] = [round($r1 / $coef_degrade), round($g1 / $coef_degrade), round($b1 / $coef_degrade)];
		$couleur1= [$r1,$g1,$b1];
		$couleur2= [$r2,$g2,$b2];
		$milieu=round(Viewer_wizard::$largeur/2);
		$couleurs_inter=Degrade::getMidColors($couleur1, $couleur2, $milieu);
		imageline(Viewer_wizard::$image, $milieu, 0, $milieu, Viewer_wizard::$hauteur, imagecolorallocate(Viewer_wizard::$image, $r1,$g1,$b1));
		foreach($couleurs_inter as $i => [$rouge_inter, $vert_inter, $bleu_inter]) {
			$couleur_allouee=imagecolorallocate(Viewer_wizard::$image, $rouge_inter,$vert_inter,$bleu_inter);
			imageline(Viewer_wizard::$image, $milieu+$i, 0, $milieu+$i, Viewer_wizard::$hauteur, $couleur_allouee);
			imageline(Viewer_wizard::$image, $milieu-$i, 0, $milieu-$i, Viewer_wizard::$hauteur, $couleur_allouee);
		}
		$noir=imagecolorallocate(Viewer_wizard::$image, 0, 0, 0);
		imagefilledrectangle(Viewer_wizard::$image, $milieu -z(.25), Viewer_wizard::$hauteur*0.2, $milieu +z(.25), Viewer_wizard::$hauteur*0.2+Viewer_wizard::$hauteur*0.05, $noir);
		imagefilledrectangle(Viewer_wizard::$image, $milieu -z(.25), Viewer_wizard::$hauteur*0.8, $milieu +z(.25), Viewer_wizard::$hauteur*0.8+Viewer_wizard::$hauteur*0.05, $noir);
	}
}

class Rectangle extends Fonction_executable {
	static $libelle='Dessiner un rectangle';
	static $champs= ['Couleur'=>'couleur','Pos_x_debut'=>'quantite','Pos_x_fin'=>'quantite','Pos_y_debut'=>'quantite','Pos_y_fin'=>'quantite','Rempli'=>'liste'];
	static $valeurs_nouveau= ['Couleur'=>'D01721','Pos_x_debut'=>'3','Pos_x_fin'=>'[Largeur]-3','Pos_y_debut'=>'3','Pos_y_fin'=>'[Hauteur]*0.5','Rempli'=>'Non'];
	static $valeurs_defaut= [];

	static $descriptions= [
        'Couleur'=>'Couleur du rectangle',
							   'Pos_x_debut'=>'Marge du d&eacute;but du rectangle par rapport &agrave; la gauche de la tranche',
							   'Pos_x_fin'=>'Marge de la fin du rectangle par rapport &agrave; la gauche de la tranche',
							   'Pos_y_debut'=>'Marge du d&eacute;but du rectangle par rapport au haut de la tranche',
							   'Pos_y_fin'=>'Marge de la fin du rectangle par rapport au haut de la tranche',
							   'Rempli'=>'"Oui" pour dessiner un rectangle rempli, "Non" pour dessiner seulement le contour'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->options->Pos_x_debut=z(self::toTemplatedString($this->options->Pos_x_debut));
		$this->options->Pos_x_fin=z(self::toTemplatedString($this->options->Pos_x_fin));
		$this->options->Pos_y_debut=z(self::toTemplatedString($this->options->Pos_y_debut));
		$this->options->Pos_y_fin=z(self::toTemplatedString($this->options->Pos_y_fin));

		[$r, $g, $b] = $this->getRGB($this->options->Couleur);
		$couleur=imagecolorallocate(Viewer_wizard::$image, $r, $g, $b);
		if ($this->options->Rempli === 'Oui') {
            imagefilledrectangle(Viewer_wizard::$image, $this->options->Pos_x_debut, $this->options->Pos_y_debut, $this->options->Pos_x_fin, $this->options->Pos_y_fin, $couleur);
        }
		else {
            imagerectangle(Viewer_wizard::$image, $this->options->Pos_x_debut, $this->options->Pos_y_debut, $this->options->Pos_x_fin, $this->options->Pos_y_fin, $couleur);
        }
	}
}

class Arc_cercle extends Fonction_executable {
	static $libelle='Dessiner un arc de cercle';
	static $champs= ['Couleur'=>'couleur','Pos_x_centre'=>'quantite','Pos_y_centre'=>'quantite','Largeur'=>'quantite','Hauteur'=>'quantite','Angle_debut'=>'quantite','Angle_fin'=>'quantite','Rempli'=>'liste'];
	static $valeurs_nouveau= ['Couleur'=>'BBBBBB','Pos_x_centre'=>'10','Pos_y_centre'=>'50','Largeur'=>'10','Hauteur'=>'20','Angle_debut'=>'0','Angle_fin'=>'360','Rempli'=>'Non'];
	static $valeurs_defaut= [];

	static $descriptions= [
        'Couleur'=>'Couleur de l\'arc de cercle',
							   'Pos_x_centre'=>'Marge du centre de l\arc par rapport &agrave; la gauche de la tranche',
							   'Pos_y_centre'=>'Marge du centre de l\arc par rapport au haut de la tranche',
							   'Largeur'=>'Largeur de l\'arc de cercle<br />(Correspond au diam&egrave;tre pour un cercle complet)',
							   'Hauteur'=>'Hauteur de l\'arc de cercle<br />(Correspond au diam&egrave;tre pour un cercle complet)',
							   'Angle_debut'=>'Angle du d&eacute;but de l\'arc de cercle<br />(0 pour un cercle complet)',
							   'Angle_fin'=>'Angle de la fin de l\'arc de cercle<br />(360 pour un cercle complet)',
							   'Rempli'=>'"Oui" pour dessiner un arc de cercle rempli, "Non" pour dessiner seulement le trait'
    ];

	function __construct($options,$executer=true,$creation=false,$get_options_defaut=true) {
		parent::__construct($options,$creation,$get_options_defaut);
		if (!$executer) {
            return;
        }
		$this->options->Pos_x_centre=z(self::toTemplatedString($this->options->Pos_x_centre));
		$this->options->Pos_y_centre=z(self::toTemplatedString($this->options->Pos_y_centre));
		$this->options->Largeur=z(self::toTemplatedString($this->options->Largeur));
		$this->options->Hauteur=z(self::toTemplatedString($this->options->Hauteur));

		[$r, $g, $b] = $this->getRGB($this->options->Couleur);
		$couleur=imagecolorallocate(Viewer_wizard::$image, $r, $g, $b);
		if ($this->options->Rempli === 'Oui') {
            imagefilledarc(Viewer_wizard::$image, $this->options->Pos_x_centre, $this->options->Pos_y_centre, $this->options->Largeur, $this->options->Hauteur, $this->options->Angle_debut, $this->options->Angle_fin, $couleur, IMG_ARC_PIE);
        }
		else {
            imagearc(Viewer_wizard::$image, $this->options->Pos_x_centre, $this->options->Pos_y_centre, $this->options->Largeur, $this->options->Hauteur, $this->options->Angle_debut, $this->options->Angle_fin, $couleur);
        }
	}
}

class Dessiner_contour {
	function __construct($dimensions) {
		if (is_null(Viewer_wizard::$image)) {
            Fonction_executable::erreur('Pas d\'infos sur cette tranche');
        }
		else {
			$noir=imagecolorallocate(Viewer_wizard::$image, 0, 0, 0);
            $maxOffset = z(0.15);
            for ($i=0; $i<$maxOffset; $i++) {
                imagerectangle(Viewer_wizard::$image, $i, $i, z($dimensions->Dimension_x) - 1 - $i, z($dimensions->Dimension_y) - 1 - $i, $noir);
            }
		}
	}
}

class Rogner {
	function __construct($pays,$magazine,$numero_original,$numero,$nom,$source,$destination,$x1,$x2,$y1,$y2) {
		$extension='.jpg';

		if ($source === 'photo_multiple') {
            $nom_image_origine = Fonction_executable::getCheminPhotosTranchesMultiples()
                                .'/photo.multiple_'.$nom.$extension;
        }
        else {
            $nom_image_origine = Fonction_executable::getCheminPhotos($pays)
                .'/'.$magazine.'.'.$numero_original.'.photo_'.$nom;
        }
		$nom_image_modifiee= ($destination === 'photos' ? Fonction_executable::getCheminPhotos($pays) : Fonction_executable::getCheminElements($pays))
							.'/'.$magazine.'.'.$numero.'.photo_';
		$i=1;
		while (file_exists($nom_image_modifiee.$i.$extension)) {
			$i++;
		}
		$nom_image_modifiee.=$i.$extension;

//		echo "$nom_image_origine : Rognage vers $nom_image_modifiee : ($x1,$y1) -> ($x2,$y2)";

		$img = imagecreatefromjpeg($nom_image_origine);
		$width=imagesx($img);
		$height =imagesy($img);
		$cropped_img=imagecreatetruecolor(($x2-$x1) * $width / 100,($y2-$y1) * $height / 100);
		imagecopyresampled ($cropped_img , $img ,
							0, 0,
							$x1 * $width / 100 , $y1 * $height / 100 ,
							($x2-$x1) * $width / 100 , ($y2-$y1) * $height / 100 ,
							($x2-$x1) * $width / 100 , ($y2-$y1) * $height / 100);
		imagejpeg($cropped_img,$nom_image_modifiee);

		echo $nom_image_modifiee;

	}
}


function z($valeur) {
	return (Viewer_wizard::$zoom ?? 1.5)*$valeur;
}

function est_dans_intervalle($numero,$intervalle) {
	if (is_null($numero)) {
        return true;
    }
	if ($intervalle === 'Tous') {
        return true;
    }
	if ($numero==$intervalle) {
        return true;
    }
	if (!isset(Modele_tranche::$numeros_dispos)) {
		$m=new Modele_tranche();
		Modele_tranche::$numeros_dispos=$m->get_numeros_disponibles(Modele_tranche::$pays,Modele_tranche::$magazine);
	}
	if (strpos($intervalle,'~')!==false) {
		$intervalles=explode(';',$intervalle);
        $numeros_debut = [];
        $numeros_fin = [];
		foreach($intervalles as $intervalle) {
			if (strpos($intervalle, '~') === false) {
                [$numero_debut, $numero_fin] = [$intervalle, $intervalle];
            }
			else {
                [$numero_debut, $numero_fin] = explode('~', $intervalle);
            }
			$numeros_debut[]=$numero_debut;
			$numeros_fin[]=$numero_fin;
		}
	}
	else {
        [$numeros_debut, $numeros_fin] = [explode(';', $intervalle), explode(';', $intervalle)];
    }

	foreach($numeros_debut as $i=>$numero_debut) {
		$numero_fin=$numeros_fin[$i];
		if ($numero_debut === $numero_fin) {
			if ($numero_debut === $numero) {
                return true;
            }
			else {
                continue;
            }
		}
		$numero_debut_trouve=false;
		foreach(Modele_tranche::$numeros_dispos as $numero_dispo) {
			if ($numero_dispo==$numero_debut) {
                $numero_debut_trouve = true;
            }
			if ($numero_dispo==$numero && $numero_debut_trouve) {
				return true;
			}
			if ($numero_dispo==$numero_fin) {
                continue 2;
            }
		}
	}
	return false;
}


function rgb2hex($r, $g, $b) {
	$hex = '';
	$rgb = [$r, $g, $b];
	for ($i = 0; $i < 3; $i++) {
		if (($rgb[$i] > 255) || ($rgb[$i] < 0)) {
			echo 'Error : input must be between 0 and 255';
			return 0;
		}
		$tmp = dechex($rgb[$i]);
		if (strlen($tmp) < 2) {
            $hex .= '0' . $tmp;
        }
		else {
            $hex .= $tmp;
        }
	}
	return strtoupper($hex);
}

function hex2rgb($color){
	if (strlen($color) != 6){
		return [0,0,0];
	}
	$rgb = [];
	for ($x=0;$x<3;$x++){
		$rgb[$x] = hexdec(substr($color, 2*$x,2));
	}
	return $rgb;
}

function getNumerosDebutFinShort($intervalle=null) {
	if (is_null($intervalle)) {
        return [Modele_tranche::$numero_debut, Modele_tranche::$numero_fin];
    }
	$numero_debut_fin=explode('~',$intervalle);
	if (count($numero_debut_fin) === 2) {
        return explode('~', $intervalle);
    }
	else {
        return [$intervalle, $intervalle];
    }
}

function decomposer_numero ($numero) {
	if ($numero === 'Tous') {
        return ['Tous', 'Tous'];
    }
	$regex_partie_numerique='#([A-Z]*)(\d*)#i';
	preg_match($regex_partie_numerique, $numero,$resultat_numero_debut);
	return [$resultat_numero_debut[1],$resultat_numero_debut[2]];
}

function trier_intervalles($intervalle1,$intervalle2) {
	if (is_array($intervalle1)) {
		usort($intervalle1,'trier_intervalles');
		usort($intervalle2,'trier_intervalles');
		$intervalle1=$intervalle1[0];
		$intervalle2=$intervalle2[0];
	}
	[$numero_debut1,] = getNumerosDebutFinShort($intervalle1);
	[$numero_debut2,] = getNumerosDebutFinShort($intervalle2);
	[$partie_litterale_debut1, $partie_numerale_debut1] = decomposer_numero($numero_debut1);
	[$partie_litterale_debut2, $partie_numerale_debut2] = decomposer_numero($numero_debut2);
	if (($partie_litterale_debut1 < $partie_litterale_debut2) || ($partie_litterale_debut1 == $partie_litterale_debut2) && ($partie_numerale_debut1 < $partie_numerale_debut2)) {
        return -1;
    }
	elseif (($partie_litterale_debut1 == $partie_litterale_debut2) && ($partie_numerale_debut1 == $partie_numerale_debut2)) {
        return 0;
    }
	else {
        return 1;
    }
}

function imagettfbbox_t($size, $angle, $fontfile, $text)
{
    // compute size with a zero angle
    $coords = imagettfbbox($size, 0, $fontfile, $text);
    // convert angle to radians
    $a = deg2rad($angle);
    // compute some usefull values
    $ca = cos($a);
    $sa = sin($a);
    $ret = [];
    // perform transformations
    for ($i = 0; $i < 7; $i += 2) {
        $ret[$i] = round($coords[$i] * $ca + $coords[$i + 1] * $sa);
        $ret[$i + 1] = round($coords[$i + 1] * $ca - $coords[$i] * $sa);
    }
    return $ret;
}

function calculateTextBox($text, $fontFile, $fontSize, $fontAngle)
{
    $rect = imagettfbbox_t($fontSize, $fontAngle, $fontFile, $text);

    $minX = min([$rect[0], $rect[2], $rect[4], $rect[6]]);
    $maxX = max([$rect[0], $rect[2], $rect[4], $rect[6]]);
    $minY = min([$rect[1], $rect[3], $rect[5], $rect[7]]);
    $maxY = max([$rect[1], $rect[3], $rect[5], $rect[7]]);

    return [
        'left' => abs($minX),
        'top' => abs($minY),
        'width' => $maxX - $minX,
        'height' => $maxY - $minY,
        'box' => $rect
    ];
}
?>
