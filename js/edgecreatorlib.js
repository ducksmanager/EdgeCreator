function est_dans_intervalle(numero,intervalle) {
	if (numero==null || intervalle.indexOf('Tous') != -1 || numero==intervalle)
		return true;
	var numeros_debut = null;
	var numeros_fin = null;
	if (intervalle.indexOf('~')!=-1) {
		var numeros_debut_fin=intervalle.split('~');
		numeros_debut=numeros_debut_fin[0].split(';');
		numeros_fin=numeros_debut_fin[1].split(';');
	}
	else {
		numeros_debut=intervalle.split(';');
		numeros_fin=intervalle.split(';');
	}
	var trouve=false;
	$.each(Object.keys(numeros_debut),function(index,i) {
		var numero_debut=numeros_debut[i];
		var numero_fin=numeros_fin[i];
		if (numero_debut === numero_fin) {
			if (numero_debut == numero) {
				trouve=true;
			}
		}
		else {
			var numero_debut_trouve=false;
			for (var numero_dispo in numeros_dispos) {
				if (numero_dispo==numero_debut)
					numero_debut_trouve=true;
				if (numero_dispo==numero && numero_debut_trouve) {
					trouve=true;
					return;
				}
				if (numero_dispo==numero_fin) 
					return;
			}
		}
	});
	return trouve;
}