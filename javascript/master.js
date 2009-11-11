var ajax_vars = {
  model: null,
  rule: null,
  segment: null,
  field: null
}

function toggle_view(visible) {
  $("#permanent-links-container > div.split-view:visible:not(#"+visible+")").hide();
  $("#permanent-links-container #"+visible).show();
}

function create_new_rule() {
  ajax_vars.rule = 'new';
  $("#current-rule").load('{{URL}}', $.extend({ xhr: "rule_form" }, ajax_vars), function () { rule_loaded(); });
}

function align_segment_arraw() {
  $("#current-segment .arrow").css({ left:
    $('#rule .selected').offset().left -
    $("#segment").offset().left +
    $('#rule .selected').width() / 2
  });
}

function cancel_rule() {
  $("#rules").load('{{URL}}', $.extend({ xhr: "load_rules" }, ajax_vars), function () { toggle_view('rules'); });
}

function save_rule() {
  // TODO
  cancel_rule();
}

function rule_loaded(selected) {
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
  $("ul.sortable").sortable({ update: function () { segments_reordered(); } });
  // Trigger the loading to the segment options
  if ($(selected).is("*")) selected.click();
  else $("ul.sortable li:first").click();
  // Hide the rules table and display the current rule form
  toggle_view('current-rule');
}

function segment_loaded() {
  align_segment_arraw();
  $("#segment-field").change(function() {
    ajax_vars.field = this.value;
    $("#rule .segment.selected").text(this.value);
    $("#segment-field-options").load('{{URL}}', $.extend({ xhr: "change_segment_type" }, ajax_vars), function () { segment_options_loaded(); });
  });
  segment_options_loaded();
}

function segment_options_loaded() {
  $("#segment-column").change(function () {
    ajax_vars.column = this.value;
    $.post('{{URL}}', $.extend({ xhr: "change_segment_options" }, ajax_vars ));
  });
  $("#segment-format").change(function () {
    ajax_vars.format = this.value;
    $.post('{{URL}}', $.extend({ xhr: "change_segment_options" }, ajax_vars ));
  });
}

function segments_reordered() {
  align_segment_arraw();
  order = $("#rule li").map(function () { return $(this).attr('id'); }).get().join(":");
  $.post('{{URL}}', $.extend({ xhr: "reorder_segments", order: order }, ajax_vars ));
}

function add_segment() {
  $.post('{{URL}}', $.extend({ xhr: "add_segment" }, ajax_vars), function (data) {
    $("#rule ul").append(data);
    rule_loaded($("#rule li:last"));
  });
}

function remove_segment() {
  $.post('{{URL}}', $.extend({ xhr: "remove_segment" }, ajax_vars), function () {
    to_be_selected = $("#rule .segment.selected").next();
    if (!to_be_selected.is("*")) to_be_selected = $("#rule .segment.selected").prev();

    $("#rule .segment.selected").remove();
    to_be_selected.click();
  });
}

$(document).ready(function () {
  $("#models").load('{{URL}}', { xhr: "load_models" }, function () {
    $("#models select").change(function () {
      ajax_vars.model = this.value;
      $("#rules").load('{{URL}}', $.extend({ xhr: "load_rules" }, ajax_vars), function () { toggle_view('rules'); });
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
