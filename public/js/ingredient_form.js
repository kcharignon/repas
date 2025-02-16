$(function() {
  console.log("File ingredient_form.js loaded");
  function updateCoefficientField() {
    let cookingUnit = $("select[id$='_defaultCookingUnit']").find("option:selected").text();
    let purchaseUnit = $("select[id$='_defaultPurchaseUnit']").find("option:selected").text();

    console.log(cookingUnit, purchaseUnit);
    if (cookingUnit !== purchaseUnit) {
      console.log("Different")
      $("#coefficient-label").text(`1 ${purchaseUnit} contient combien de ${cookingUnit} ?`);
      $("#coefficient-group").show();
    } else {
      console.log("Same")
      $("input[id$='_coefficient']").val(""); // Reset le champ
      $("#coefficient-group").hide();
    }
  }

  // Écoute les changements sur les champs d'unité
  $(document).on("change", "select[id$='_defaultCookingUnit'], select[id$='_defaultPurchaseUnit']", function(){
    console.log("change");
    updateCoefficientField();
  })
});
