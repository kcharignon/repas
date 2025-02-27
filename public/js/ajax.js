$(document).ready(function(){
  console.log("File ajax.js loaded");

  $(document).on("click", "[data-action='btn-ajax']", function(event) {
    handleAjaxAction(this);
  });

  $(document).on("change", "[data-action='input-ajax']", function(event) {
    handleAjaxAction(this);
  });

  function handleAjaxAction(element) {
    let confirmMessage = $(element).data("confirm");

    // Si un message de confirmation est défini, demander à l'utilisateur
    if (typeof confirmMessage !== 'undefined' && !confirm(confirmMessage)) {
      return;
    }

    let loader = $(element).find("i[data-loader]");

    // Affichage du loader
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

    let url = $(element).data("url");
    let replace = $(element).data("url-value");
    if (typeof replace !== 'undefined') {
      let inputValue = $(element).val();
      console.log("replace: "+replace+" by "+inputValue);
      url = url.replace(new RegExp(`${replace}`, 'g'), inputValue);
    }

    console.log("Route call : ("+ $(element).data("method") +")" + url);

    // Exécution de la requête AJAX
    $.ajax({
      url: url,
      method: $(element).data("method"),
    }).done(function(data) {
      console.log("Done : ", data);
      showAlerts(data);
      showViews(data, element);
    }).fail(function() {
      console.log("Fail");
      showAlerts({"alerts":[{"status":"error", "message":"Une erreur est survenue"}]});
    });
  }

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

  function showAlert(status, message, timeout = 1000) {
    const html = `
    <div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 9999;">
      <div class="flash-alert alert alert-${status}" style="display: none;">
        ${message}
      </div>
    </div>`;

    const $alert = $(html);

    $(".container").prepend($alert);
    $alert.find(".flash-alert").fadeIn(250); // FadeIn en 500ms

    setTimeout(function () {
      console.log("remove alert");
      $alert.find(".flash-alert").fadeOut(250, function () {
        $(this).parent().remove(); // Supprime complètement l'alerte après le fadeOut
      });
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

    // Convertir la valeur en booléen correctement
    let hide = $(item).data('hide');
    console.log("hide:", hide);

    console.log("✅ Élément trouvé, remplacement en cours...");
    if (html.length === 0) {
      target.fadeOut(300, function() { $(this).remove(); });
    } else {
      target.replaceWith(html);
      if (shouldHide(hide)) {
        $(selector).hide();
      }
    }
  }

  function shouldHide(device) {
    if (!device ?? null) {
      return false;
    }

    if (device === 'both') {
      return true;
    }

    if ((device === 'mobile' && isMobile()) || (device === 'desktop' && !isMobile())) {
      return true;
    }

    return false;
  }
});
