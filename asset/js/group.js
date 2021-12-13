$(document).ready(function() {

/* Group a resource. */

// Add the selected group to the edit panel.
$('#group-selector .selector-child').click(function(event) {
    event.preventDefault();

    $('#group-resources').removeClass('empty');
    var groupName = $(this).data('child-search');

    if ($('#group-resources').find(`input[value="${groupName}"]`).length) {
        return;
    }

    var row = $($('#group-template').data('template'));
    row.children('td.group-name').text(groupName);
    row.find('td > input').val(groupName);
    $('#group-resources > tbody:last').append(row);
});

// Remove a group from the edit panel.
$('#group-resources').on('click', '.o-icon-delete', function(event) {
    event.preventDefault();

    var removeLink = $(this);
    var groupRow = $(this).closest('tr');
    var groupInput = removeLink.closest('td').find('input');
    groupInput.prop('disabled', true);

    // Undo remove group link.
    var undoRemoveLink = $('<a>', {
        href: '#',
        class: 'fa fa-undo',
        title: Omeka.jsTranslate('Undo remove group'),
        click: function(event) {
            event.preventDefault();
            groupRow.toggleClass('delete');
            groupInput.prop('disabled', false);
            removeLink.show();
            $(this).remove();
        },
    });

    groupRow.toggleClass('delete');
    undoRemoveLink.insertAfter(removeLink);
    removeLink.hide();
});

/* Update groups. */

// Update the name of a group.
$('.groups .o-icon-edit.contenteditable')
    .on('click', function(e) {
        e.preventDefault();
        var field = $(this).closest('td').find('.group-name');
        field.focus();
    });

// Update the name of a group.
$('.groups .group-name[contenteditable=true]')
    .focus(function() {
        var field = $(this);
        field.data('original-text', field.text());
    })
    .blur(function(e) {
        var field = $(this);
        var oldText = field.data('original-text');
        var newText = $.trim(field.text().replace(/\s+/g,' '));
        $.removeData(field, 'original-text');
        if (newText.length > 0 && newText !== oldText) {
            var url = field.data('update-url');
            $.post({
                url: url,
                data: {text: newText},
                beforeSend: function() {
                    field.text(newText);
                    field.addClass('o-icon-transmit');
                }
            })
            .done(function(data) {
                var row = field.closest('tr');
                field.text(data.content.text);
                field.data('update-url', data.content.urls.update);
                row.find('[name="resource_ids[]"]').val(data.content.escaped);
                row.find('.o-icon-delete').data('sidebar-content-url', data.content.urls.delete_confirm);
                row.find('.o-icon-more').data('sidebar-content-url', data.content.urls.show_details);
            })
            .fail(function(jqXHR, textStatus) {
                var msg = jqXHR.hasOwnProperty('responseJSON')
                    && typeof jqXHR.responseJSON.error !== 'undefined'
                    ? jqXHR.responseJSON.error
                    : Omeka.jsTranslate('Something went wrong');
                alert(msg);
                field.text(oldText);
            })
            .always(function () {
                field.removeClass('o-icon-transmit');
                field.parent().focus();
            });
        } else {
            field.text(oldText);
        }
    })
    .keydown(function(e) {
        if (e.keyCode === 13) {
            e.preventDefault();
        }
    })
    .keyup(function(e) {
        if (e.keyCode === 13) {
            $(this).blur();
        } else if (e.keyCode === 27) {
            var field = $(this);
            var oldText = field.data('original-text');
            $.removeData(field, 'original-text');
            field.text(oldText);
            field.parent().focus();
        }
    });

});
