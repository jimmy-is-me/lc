jQuery(function ($) {
  $(document).on('change', '.tgo-confirmation-toggle', function () {
    const input = $(this);
    input.prop('disabled', true);
    $.post(tgoOrderPermissions.ajaxUrl, {
      action: 'tgo_toggle_order_confirmation',
      nonce: tgoOrderPermissions.nonce,
      order_id: input.data('order-id'),
      confirmed: input.prop('checked') ? 'yes' : 'no'
    }).fail(function () {
      input.prop('checked', !input.prop('checked'));
      window.alert('無此權限或儲存失敗。');
    }).always(function () {
      input.prop('disabled', false);
    });
  });
});
