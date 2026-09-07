jQuery(function ($) {

  /* ── 確認狀態 toggle ─────────────────────────────── */
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

  /* ── 狀態列表拒曳排序 ──────────────────────────── */
  var $tbody = $('.tgo-table-statuses tbody');
  if ($tbody.length) {
    $tbody.sortable({
      handle: '.tgo-drag-handle',
      axis: 'y',
      cursor: 'grabbing',
      placeholder: 'tgo-sortable-placeholder',
      forcePlaceholderSize: true,
      update: function () {
        // 更新隐藏的 status_order[] 欄位
        var $form = $tbody.closest('form');
        $form.find('.tgo-status-order-input').remove();
        $tbody.find('tr').each(function () {
          var key = $(this).data('status-key');
          if (key) {
            $('<input>').attr({
              type: 'hidden',
              name: 'status_order[]',
              'class': 'tgo-status-order-input',
              value: key
            }).appendTo($form);
          }
        });
      }
    });
    // 頁面載入時即建立預設順序欄位（避免未拒曳就提交會遗失順序）
    var $form = $tbody.closest('form');
    $tbody.find('tr').each(function () {
      var key = $(this).data('status-key');
      if (key) {
        $('<input>').attr({
          type: 'hidden',
          name: 'status_order[]',
          'class': 'tgo-status-order-input',
          value: key
        }).appendTo($form);
      }
    });
  }

});
