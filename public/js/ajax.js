$(document).ready(function(){
  console.log("File ajax.js loaded");

  $(document).on("click", "[data-action='btn-ajax']", function(event) {
    var loader = $(this).find("i[data-loader]");
    console.log(loader);
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
    console.log("route call : " + $(this).data("url"));
    $.ajax({
      url: $(this).data("url"),
    }).done(function(data) {
      console.log(data);
      showAlerts(data);
      showViews(data, event.target);
    }).fail(function() {
      showAlerts({"alerts":[{"status":"error", "message":"Une erreur est survenue"}]});
    });
  });

  $(document).on("click", "[data-action='link']", function(event) {
    var url = $(this).data("url");
    console.log("redirect to "+url);
    window.location.href = url;
  });

  function showAlerts(data) {
    if ("alerts" in data) {
      for (const alert of data["alerts"]) {
        showAlert(alert["status"], alert["message"]);
      }
    }
  }

  function showAlert(status, message, timeout=1000) {
    const html = "" +
      "<div class='alert alert-dismissible alert-" + status + "'>" +
      "<button type='button' class='btn-close' data-bs-dismiss='alert'></button>" +
      message +
      "</div>";
    $(".container").prepend(html);
    setTimeout(function(){
      console.log("remove alert");
      console.log($(".alert .alert-dismissible"));
      $(".alert.alert-dismissible").remove();
    }, timeout);
  }

  function showViews(data, item) {
    if ("views" in data) {
      for (const view of data["views"]) {
        showView(view["selector"], view["html"], item);
      }
    }
  }

  function showView(selector, html, item) {
    if (selector === "this") {
      $(item).replaceWith(html);
    } else {
      $(selector)?.replaceWith(html);
    }
  }
});
