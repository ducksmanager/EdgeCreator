<?php

class Upload_Wizard extends CI_Controller {

    var $contenu = '';
	
	function index() {

        $est_photo_tranche = (isset($_POST['photo_tranche']) && $_POST['photo_tranche'] == 1)
                          || (isset($_GET ['photo_tranche']) && $_GET ['photo_tranche'] == 1)
            ? 1
            : 0;

        if (!isset($_POST['MAX_FILE_SIZE'])) {
            header('Location: '.preg_replace('#/[^/]+\?#','/image_upload.php?',$_SERVER['REQUEST_URI']));
            exit;
        }

        $pays     = isset($_POST['pays'])     ? $_POST['pays']     : null;
        $magazine = isset($_POST['magazine']) ? $_POST['magazine'] : null;
        $numero   = isset($_POST['numero'])   ? $_POST['numero']   : null;

        $this->load->helper('noms_images');

        list($dossier,$fichier) = get_nom_fichier($_FILES['image']['name'], $pays, $magazine, $numero, $est_photo_tranche);
        $extension = strtolower(strrchr($_FILES['image']['name'], '.'));

        $taille_maxi = $_POST['MAX_FILE_SIZE'];
        $taille = filesize($_FILES['image']['tmp_name']);
        $extensions = $est_photo_tranche ? array('.jpg','.jpeg') : array('.png');
        //D�but des v�rifications de s�curit�...
        if(!in_array($extension, $extensions)) //Si l'extension n'est pas dans le tableau
        {
            $erreur = 'Vous devez uploader un fichier de type '.implode(' ou ',$extensions);
        }
        if($taille>$taille_maxi)
        {
            $erreur = 'Le fichier est trop gros.';
        }
        if (file_exists($dossier . $fichier)) {
            $erreur = 'Echec de l\'envoi : ce fichier existe d&eacute;j&agrave; ! '
                .'Demandez &agrave; un admin de supprimer le fichier existant ou renommez le v&ocirc;tre !';
        }
        if(!isset($erreur)) //S'il n'y a pas d'erreur, on upload
        {
            //On formate le nom du fichier ici...
            $fichier = strtr($fichier,
                '����������������������������������������������������',
                'AAAAAACEEEEIIIIOOOOOUUUUYaaaaaaceeeeiiiioooooouuuuyy');

            if (@opendir($dossier) === false) {
                mkdir($dossier,0777,true);
            }
            if(move_uploaded_file($_FILES['image']['tmp_name'], $dossier . $fichier)) {
                if ($est_photo_tranche) {
                    if ($extension == '.png') {
                        $im=imagecreatefrompng($dossier . $fichier);
                        unlink($dossier . $fichier);
                        $fichier=str_replace('.png','.jpg',$fichier);
                        imagejpeg($im, $dossier . $fichier);
                    }

                    ob_start();
                    ?>
                    <script type="text/javascript">
                        if (window.parent.document.getElementById('wizard-photos').parentNode.style.display === 'block') {
                            window.parent.lister_images_gallerie('Photos');
                        }
                        else {
                            window.parent.afficher_photo_tranche();
                        }
                    </script><?php
                    $this->contenu.= ob_get_flush();
                }
                $this->contenu .= 'Envoi r&eacute;alis&eacute; avec succ&egrave;s !';
                if (isset($pays)) {
                    $this->contenu .= afficher_retour($est_photo_tranche);
                }
                else {
                    ob_start();
                    ?>
                    <script type="text/javascript">
                        window.parent.nom_photo_tranches_multiples = '<?=$fichier?>';
                        window.parent.$('.ui-dialog:visible')
                            .find('button')
                            .filter(function() {
                                return window.parent.$(this).text() === 'Suivant';
                            }).button('option','disabled', false);
                    </script><?php
                    $this->contenu.= ob_get_flush();
                }
            }
            else {
                $this->contenu .= 'Echec de l\'envoi !';
                $this->contenu .= afficher_retour($est_photo_tranche);
            }
        }
        else {
            $this->contenu .= $erreur;
            $this->contenu .= afficher_retour($est_photo_tranche);
        }

        $this->load->view('helperview',array('contenu'=>$this->contenu));
    }

    function afficher_retour($est_photo_tranche) {
        ob_start();
        ?><br /><a href="<?=$_SERVER['REDIRECT_URL'].'?photo_tranche='.$est_photo_tranche?>">Autre envoi</a><?php
        return ob_get_flush();
    }
}
?>
