jQuery(document).ready(function () {

  setPay();
  jQuery('body').on('updated_checkout', function () {
    usingGateway()
  });

});

jQuery(document).on("change", "form[name='checkout'] input[name='payment_method']", function () {
  if (0 === jQuery('form[name="checkout"] input[name="payment_method"]').filter(':checked').size()) {
    jQuery(this).prop('checked', true).attr('checked', 'checked');
  };
  usingGateway();
});

function usingGateway() {
  if (jQuery("form[name='checkout'] input[name='payment_method']:checked").val() === 'quickpenny') {
    jQuery('form[name="checkout"] button[type="submit"]').addClass('qp-button')
    window.localStorage.setItem('pay-with-qp', 'true')

    if (getCookie('__payment_method__') == 'quickpennyexpress') {

      var cancel = `<p id="ppcp-cancel" class="has-text-align-center ppcp-cancel">
			Actualmente estás pagando con QuickPenny. Si quieres cancelar este proceso, por favor, haz clic <a id="qp-cancel" href="javascript:;">aquí</a>.		</p>`

      if (quickpennyCheckout && quickpennyCheckout.locale) {
        let locale_validate = quickpennyCheckout.locale.indexOf("es");
        if (locale_validate == -1) {
          //English
          cancel = `<p id="ppcp-cancel" class="has-text-align-center ppcp-cancel">
          You are currently paying with QuickPenny. If you want to cancel this process, please click <a id="qp-cancel" href="javascript:;">here</a>.		</p>`
        }
      }

      const payment_method = `<input id="payment-method-qp" type="hidden" name="__payment_method__" value="quickpennyexpress">`

      if (jQuery('#payment-method-qp').length) {
      } else {
        jQuery('.form-row.place-order').append(payment_method);
      }

      if (jQuery('#ppcp-cancel').length) {
      } else {
        jQuery('.form-row.place-order').append(cancel);
      }

      jQuery("#qp-cancel").on("click", function (e) {
        document.cookie = "__payment_method__=;expires=Thu, 01 Jan 1970 00:00:01 GMT;path=/"
        document.cookie = "__user_token_quickpenny__=;expires=Thu, 01 Jan 1970 00:00:01 GMT;path=/"
        setTimeout(() => {
          window.location.reload();
        }, 500);
      });
    }

  } else {
    // Not using gateway
    jQuery('form[name="checkout"] button[type="submit"]').removeClass('qp-button')
    window.localStorage.removeItem('pay-with-qp')

  }
  setPay();
};

function setPay() {
  const pay = window.localStorage.getItem('pay-with-qp')
  if (pay) {
    if (jQuery('select#billing_country').length > 0) {
      jQuery('select#billing_country').val('US')
    }
    setTimeout(() => {
      if (jQuery("form[name='checkout'] input[name='payment_method']").length > 0) {
        jQuery('form[name="checkout"] input[name="payment_method"][value="quickpenny"]').attr('checked', 'checked');
        jQuery('form[name="checkout"] button[type="submit"]').addClass('qp-button')
      }

    }, 1500)
  }
}

function getCookie(cname) {
  let name = cname + "=";
  let ca = document.cookie.split(';');
  for (let i = 0; i < ca.length; i++) {
    let c = ca[i];
    while (c.charAt(0) == ' ') {
      c = c.substring(1);
    }
    if (c.indexOf(name) == 0) {
      return c.substring(name.length, c.length);
    }
  }
  return "";
}