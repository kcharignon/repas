$(document).ready(function(){

  function checkShoppingListStatus()
  {
    if (isMobile()) {
      showButton();
      showIngredients();
    } else {
      hideButtons();
    }
  }

  function hideButtons() {
    $('#button-show-ingredients').hide();
    $('#button-show-recipes').hide();
    $('#shoppingListIngredientsColumn').children('h2').show();
    $('#shopping-list-recipes-column').children('h2').show();
  }

  function showButton() {
    $('#button-show-ingredients').show();
    $('#button-show-recipes').show();
    $('#shoppingListIngredientsColumn').children('h2').hide();
    $('#shopping-list-recipes-column').children('h2').hide();
  }

  function showIngredients() {
    $('#shoppingListIngredientsColumn').show();
    $('#shopping-list-recipes-column').hide();
    $('#button-show-ingredients').addClass("btn-primary");
    $('#button-show-ingredients').removeClass("btn-secondary");
    $('#button-show-recipes').addClass("btn-secondary");
    $('#button-show-recipes').removeClass("btn-primary");
  }

  function showRecipes() {
    $('#shopping-list-recipes-column').show();
    $('#shoppingListIngredientsColumn').hide();
    $('#button-show-recipes').addClass("btn-primary");
    $('#button-show-recipes').removeClass("btn-secondary");
    $('#button-show-ingredients').addClass("btn-secondary");
    $('#button-show-ingredients').removeClass("btn-primary");
  }

  checkShoppingListStatus();

  $(document).on("click", "#button-show-ingredients", showIngredients);
  $(document).on("click", "#button-show-recipes", showRecipes);
  $(document).on("click", "#button-shopping-list-go-to-shopping", function () {
    if (isMobile()) {
      showButton();
      showIngredients();
    }
  });
  $(document).on("click", "#button-shopping-list-back-to-planning", function() {
    hideButtons();
    showRecipes();
  });
});
