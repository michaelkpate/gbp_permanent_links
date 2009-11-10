var ajax_vars = {
  rule: null,
  segment: null,
  field: null
}

function toggle_view(visible) {
  $("#permanent-links-container > div.split-view:visible:not(#"+visible+")").hide();
  $("#permanent-links-container #"+visible).show();
}

function create_new_rule() {
  $("#current-rule").load('{{URL}}', { xhr: "rule_form", model: $("#models select").attr('value') }, function () { toggle_view('current-rule'); });
}

function align_segment_arraw() {
  $("#current-segment .arrow").css({ left:
    $('#rule .selected').offset().left -
    $("#segment").offset().left +
    $('#rule .selected').width() / 2
  });
}

function cancel_rule() {
  toggle_view('rules');
}

function save_rule() {
  // TODO
  cancel_rule();
}

function rule_loaded() {
  ajax_vars.rule = $("#rule ul").attr('id');

  $("ul.sortable li").hover(
    function() { $(this).addClass('hover'); },
    function() { $(this).removeClass('hover'); }
  ).click(function() {
    if ($(this).hasClass('selected')) return;
    $("ul.sortable li").removeClass('selected');
    $(this).addClass('selected');
    ajax_vars.segment = this.id;
    ajax_vars.field = null;
    $("#segment").load('{{URL}}', $.extend({ xhr: "load_segment" }, ajax_vars), function () { segment_loaded(); });
  });
  $("ul.sortable").sortable({ update: function () { align_segment_arraw(); } });
  // Trigger the loading to the segment options
  $("ul.sortable li:first").click();
  // Hide the rules table and display the current rule form
  toggle_view('current-rule');
}

function segment_loaded() {
  align_segment_arraw();
  $("#segment-field").change(function() {
    ajax_vars.field = this.value;
    $("#rule .segment.selected").text(this.value);
    $("#segment-field-options").load('{{URL}}', $.extend({ xhr: "change_segment_type" }, ajax_vars));
  });
}

$(document).ready(function () {
  $("#models").load('{{URL}}', { xhr: "load_models" }, function () {
    $("#models select").change(function () {
      $("#rules").load('{{URL}}', { xhr: "load_rules", model: this.value }, function () { toggle_view('rules'); });
    }).change();
  });
});

$(document).ajaxComplete(function (request, settings) {
  $("a.remote").unbind('click').click(function () {
    var xhr_method = new RegExp("[\\?&]xhr=([^&#]*)").exec(this.href)[1];
    switch (xhr_method) {
      case 'rule_form':
        $("#current-rule").load(this.href, {}, function () { rule_loaded(); });
      break;
      default:
        $.post(this.href, function (data) { eval(data); });
      break;
    }
    return false;
  });
});
