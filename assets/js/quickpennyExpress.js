
const bufferABase64 = buffer => btoa(String.fromCharCode(...new Uint8Array(buffer)));
const base64ABuffer = buffer => Uint8Array.from(atob(buffer), c => c.charCodeAt(0));
const LONGITUD_SAL = 16;
const LONGITUD_VECTOR_INICIALIZACION = LONGITUD_SAL;

jQuery(document).ready(function () {

  if (jQuery(".wc-proceed-to-checkout").length > 0) {
    loadScriptQp();
  }

  jQuery("body").on("updated_cart_totals", function () {
    loadScriptQp();
  });
});

function setCookie(cname, cvalue, exdays) {
  const d = new Date();
  d.setTime(d.getTime() + (exdays * 24 * 60 * 60 * 1000));
  let expires = "expires=" + d.toUTCString();
  document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}

function updateOrderReviewQp(data) {


  if (data.user_token) {
    setCookie('__user_token_quickpenny__', data.user_token, 1)
  }
  if (data.transactionID) {
    setCookie('__ts_id_quickpenny__', data.transactionID, 1)
  }

  jQuery.ajax({
    type: "post",
    url: `${ajax_var.siteurl}/?wc-ajax=qpenny_update_order_review`,
    data: data,
    success: function (response) {
      console.log(response);
      if (response.result && response.result == "success") {
        setCookie('__payment_method__', 'quickpennyexpress', 1)
        setTimeout(() => {
          window.location.href = ajax_var.urlCheckout;
        }, 500);

      }
    },
  });
}

function loadScriptQp() {

  if (ajax_var) {
    let expressWindow;
    var intervalPayment;

    jQuery("#overlay-qp-load").on("click", function (e) {
      if (expressWindow) {
        expressWindow.focus();
      }
    });
    jQuery(".qp-checkout-close").on("click", function (e) {
      if (expressWindow) {
        expressWindow.close();
      }
      jQuery("#overlay-qp-load").hide();
    });

    jQuery("#button-quick-penny-express").on("click", function (e) {

      jQuery.ajax({
        type: "post",
        url: ajax_var.createorder,
        data: "nonce=" + ajax_var.nonce,
        success: function (result) {
          if (result && result.success) {

            jQuery("#overlay-qp-load").show();

            var top = window.screen.height - 700;
            top = top > 0 ? top / 2 : 0;

            var left = window.screen.width - 500;
            left = left > 0 ? left / 2 : 0;


            result.data.info_merchant['client_id'] = ajax_var.client_id;
            result.data.info_merchant['client_secret'] = ajax_var.client_secret;

            expressWindow = window.open(
              ajax_var.express_checkout_url + "?meta=" +
              window.btoa(encodeURIComponent(JSON.stringify(result.data))),
              "qp-payment",
              "width=500,height=700, toolbar=0" +
              ",top=" +
              top +
              ",left=" +
              left
            );
            window.addEventListener(
              "message",
              (event) => {
                if (
                  event.data.resultExpress &&
                  event.data.commerceGateway == "quick-penny"
                ) {
                  // console.log(event.data);
                   updateOrderReviewQp(event.data);
                }
              },
              false
            );
            expressWindow.focus();

            intervalPayment = setInterval(() => {
              if (expressWindow.closed) {
                jQuery("#overlay-qp-load").hide();
                clearInterval(intervalPayment);
              }
            }, 1000);
          }
        },
      });
      e.preventDefault();
    });
  }
}
