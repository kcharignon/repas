$(function () {
  console.log("File create_recipe.js loaded");

  let collectionHolder = $('#recipe-rows ul'); // Sélectionne la liste des ingrédients
  let addButton = $('#add-row'); // Sélectionne le bouton d'ajout

  if (!collectionHolder.length) {
    console.error("Impossible de trouver l'élément contenant la collection.");
    return;
  }

  let prototype = collectionHolder.data('prototype'); // Récupère le prototype
  if (!prototype) {
    console.error("Aucun prototype trouvé. Vérifiez le form_widget(form.rows.vars.prototype).");
    return;
  }

  addButton.on('click', function () {
    let index = collectionHolder.children().length; // Compte les éléments existants
    let newForm = prototype.replace(/__name__/g, index); // Remplace le placeholder

    let newElement = $('<li></li>').html(newForm); // Crée un nouvel élément <li>
    collectionHolder.append(newElement); // Ajoute à la liste
  });
});
