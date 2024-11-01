'use strict';

(function($) {
  $(function() {
    woost_overwrite();
    woost_arrange();
    woost_apply();
    woost_type();
    woost_roles();
  });

  $(document).on('change', '.woost_apply', function() {
    woost_apply();
  });

  $(document).on('change', '.woost_type', function() {
    woost_type();
  });

  $(document).on('click touch', '.woost-field-head', function() {
    $(this).closest('.woost-field').toggleClass('woost-field-close');
  });

  $(document).on('click touch', '.woost-tab-new', function() {
    var $btn = $(this);

    $btn.prop('disabled', true);

    var data = {
      action: 'woost_add_tab', product_id: $btn.data('product_id'),
    };

    $.post(ajaxurl, data, function(response) {
      var editor = $(response).find('.woost-textarea').attr('id');

      $('.woost-tabs').append(response);
      woost_arrange();
      woost_apply();
      woost_type();
      woost_roles();
      woost_editor(editor);
      $btn.prop('disabled', false);
    });
  });

  $(document).on('click touch', '.woost-tab-remove', function() {
    var r = confirm('Do you want to remove this tab? This action cannot undo.');

    if (r == true) {
      $(this).closest('.woost-tab').remove();
    }
  });

  $(document).on('click touch', '.woost-tab-header', function(e) {
    if (($(e.target).closest('.woost-tab-duplicate').length === 0) &&
        ($(e.target).closest('.woost-tab-remove').length === 0)) {
      $(this).closest('.woost-tab').toggleClass('active');
    }
  });

  $(document).on('keyup change keypress', '.woost-tab-title-input', function() {
    let $this = $(this), value = $this.val();
    $this.closest('.woost-tab').find('.woost-tab-title').text(value);
  });

  $(document).on('change', 'input[name="woost_overwrite"]', function() {
    woost_overwrite();
  });

  function woost_arrange() {
    $('.woost-tabs').sortable({
      handle: '.woost-tab-move',
    });
  }

  function woost_overwrite() {
    if ($('#woost_settings').length) {
      var val = $('input[name="woost_overwrite"]:checked').val();

      if (val === 'overwrite' || val === 'prepend' || val === 'append') {
        $('.woost-tabs-global').hide();
        $('.woost-tabs-wrapper').show();
      } else {
        $('.woost-tabs-wrapper').hide();
        $('.woost-tabs-global').show();
      }
    }
  }

  function woost_apply() {
    $('.woost_apply').each(function() {
      var $this = $(this);
      var apply = $this.val();
      var apply_text = $this.find('option:selected').text();
      var $terms = $this.closest('.woost-tab').find('.woost_terms');
      var $products = $this.closest('.woost-tab').find('.woost_products');

      $this.closest('.woost-tab').
          find('.woost-tab-label-apply').
          html(apply_text);

      $terms.closest('.woost-tab').find('.woost_hide').hide();
      $terms.closest('.woost-tab').find('.woost_show').show();
      $terms.closest('.woost-tab').find('.hide_if_apply_' + apply).hide();
      $terms.closest('.woost-tab').find('.show_if_apply_' + apply).show();

      $terms.selectWoo({
        ajax: {
          url: ajaxurl, dataType: 'json', delay: 250, data: function(params) {
            return {
              q: params.term, action: 'woost_search_term', taxonomy: apply,
            };
          }, processResults: function(data) {
            var options = [];

            if (data) {
              $.each(data, function(index, text) {
                options.push({id: text[0], text: text[1]});
              });
            }
            return {
              results: options,
            };
          }, cache: true,
        }, minimumInputLength: 1,
      });

      if ((typeof $terms.data(apply) === 'string' ||
          $terms.data(apply) instanceof String) && $terms.data(apply) !== '') {
        $terms.val($terms.data(apply).split(',')).change();
      } else {
        $terms.val([]).change();
      }

      $products.selectWoo({
        allowClear: true, ajax: {
          url: ajaxurl, dataType: 'json', delay: 250, data: function(params) {
            return {
              term: params.term, action: 'woost_search_product',
            };
          }, processResults: function(data) {
            var options = [];

            if (data) {
              $.each(data, function(index, text) {
                options.push({id: text[0], text: text[1]});
              });
            }

            return {
              results: options,
            };
          }, cache: true,
        }, minimumInputLength: 1,
      });
    });
  }

  function woost_editor(editor) {
    wp.editor.initialize(editor, {
      mediaButtons: true, tinymce: {
        wpautop: true,
        plugins: 'charmap colorpicker compat3x directionality fullscreen hr image lists media paste tabfocus textcolor wordpress wpautoresize wpdialogs wpeditimage wpemoji wpgallery wplink wptextpattern wpview',
        toolbar1: 'formatselect bold italic | bullist numlist | blockquote | alignleft aligncenter alignright | link unlink | wp_more | spellchecker',
      }, quicktags: true,
    });
  }

  function woost_roles() {
    $('.woost_roles').selectWoo();
  }

  function woost_type() {
    $('.woost_type').each(function() {
      var $this = $(this);
      var type = $this.val();

      $this.closest('.woost-tab').
          find('.woost-tab-label-type').
          html('#' + type);

      $this.closest('.woost-tab').find('.woost-tab-line-hide').hide();
      $this.closest('.woost-tab').
          find('.woost-tab-line-show-if-' + type).
          show();
    });
  }
})(jQuery);