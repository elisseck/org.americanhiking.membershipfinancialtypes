CRM.$(function($) {
  //hide the checkbox
  $('.membership-section').hide();
  //nonmember price field
  $('input[name="price_22"][value="67"]').click(function() {
    if ($('input[name="price_22"][value="67"]').prop('checked') == true && !$('#price_32_104').prop('checked') == true) {
      $('#price_32_104').trigger('click');
    }
  });
  //member price field
  $('input[name="price_22"][value="66"]').click(function() {
    if ($('input[name="price_22"][value="66"]').prop('checked') == true && $('#price_32_104').prop('checked') == true) {
      $('#price_32_104').trigger('click');
    }
  });
  //additional trip and youth fields
  $('input[name="price_22"][value="68"]').click(function() {
    if ($('input[name="price_22"][value="68"]').prop('checked') == true && $('#price_32_104').prop('checked') == true) {
      $('#price_32_104').trigger('click');
    }
  });
  $('input[name="price_22"][value="69"]').click(function() {
    if ($('input[name="price_22"][value="69"]').prop('checked') == true && $('#price_32_104').prop('checked') == true) {
      $('#price_32_104').trigger('click');
    }
  });
});
