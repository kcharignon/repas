$(document).ready(function(){
  console.log("File ajax.js loaded");

  $(document).on("click", "[data-action='btn-ajax']", function(event) {

    confirmMessage = $(this).data("confirm")
    console.log(confirmMessage)
    if (confirmMessage.length > 0 && !confirm(confirmMessage)) {
      return;
    }
    var loader = $(this).find("i[data-loader]");
    console.log(loader);
    // On affiche le loader
    switch (loader.data("loader")) {
      case "replace":
        loader.replaceWith("<i class='fas fa-spinner fa-spin'></i>");
        break;
      case "show":
        loader.removeClass("hidden");
        break;
      default:
        break;
    }
    console.log("Route call : ("+ $(this).data("method") +")" + $(this).data("url"));
    // On appele la route
    $.ajax({
      url: $(this).data("url"),
      method: $(this).data("method"),
    }).done(function(data) {
      //On affiche les alerte et la vue
      console.log("Done : ", data);
      showAlerts(data);
      showViews(data, event.target);
    }).fail(function() {
      console.log("Fail : ", data);
      showAlerts({"alerts":[{"status":"error", "message":"Une erreur est survenue"}]});
    });
  });

  $(document).on("click", "[data-action='link']", function(event) {
    var url = $(this).data("url");
    console.log("redirect to "+url);
    window.location.href = url;
  });

  function showAlerts(data) {
    if (typeof data !== 'undefined' && "alerts" in data) {
      for (const alert of data["alerts"]) {
        showAlert(alert["status"], alert["message"]);
      }
    }
  }

  function showAlert(status, message, timeout=1000) {
    const html = "" +
      "<div class='position-fixed top-0 start-50 translate-middle-x p-3' style='z-index: 9999'>"+
      "<div class='alert alert-dismissible alert-" + status + "'>" +
      "<button type='button' class='btn-close' data-bs-dismiss='alert'></button>" +
      message +
      "</div>" +
      "</div>";
    $(".container").prepend(html);
    setTimeout(function(){
      console.log("remove alert");
      console.log($(".alert .alert-dismissible"));
      $(".alert.alert-dismissible").remove();
    }, timeout);
  }

  function showViews(data, item) {
    console.log("showViews", data, item);
    if (typeof data !== 'undefined' && "views" in data) {
      for (const view of data["views"]) {
        showView(view["selector"], view["html"], item);
      }
    }
  }

  function showView(selector, html, item) {
    console.log("showView", selector, html, item);
    const target = (selector === "this") ? $(item) : $(selector);

    if (target.length === 0) {
      console.error("⚠️ Aucun élément trouvé pour le sélecteur:", selector);
      return;
    }

    console.log("✅ Élément trouvé, remplacement en cours...");
    if (html.length === 0) {
      target.fadeOut(300, function() { $(this).remove(); });
    } else {
      target.replaceWith(html);
    }
  }
});
