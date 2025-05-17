(function($) {
    'use strict';

    const MUC = {
        init: function() {
            this.initSelectAll();
            this.initBulkActions();
            this.initDeleteConfirmation();
            this.initForceCheck();
            this.initResponsiveTables();
        },

        initSelectAll: function() {
            const $selectAll = $('#select-all');
            const $checkboxes = $('input[name="selected_media[]"]');

            $selectAll.on('change', function() {
                $checkboxes.prop('checked', $(this).prop('checked'));
            });

            $checkboxes.on('change', function() {
                $selectAll.prop('checked', $checkboxes.length === $checkboxes.filter(':checked').length);
            });
        },

        initBulkActions: function() {
            $('form').on('submit', function(e) {
                const $selected = $('input[name="selected_media[]"]:checked');
                if ($(this).find('input[name="bulk_delete"]').length && !$selected.length) {
                    e.preventDefault();
                    alert('Por favor, seleccione al menos un archivo para eliminar.');
                }
            });
        },

        initDeleteConfirmation: function() {
            $('.button-secondary[name="delete_media"]').on('click', function(e) {
                if (!confirm('¿Está seguro de que desea eliminar este archivo?')) {
                    e.preventDefault();
                }
            });

            $('input[name="bulk_delete"]').on('click', function(e) {
                if (!confirm('¿Está seguro de que desea eliminar los archivos seleccionados?')) {
                    e.preventDefault();
                }
            });
        },

        initForceCheck: function() {
            jQuery(document).ready(function($) {
                // Verificar si hay una verificación en progreso
                var isChecking = false;
                
                function checkProgress() {
                    if (!isChecking) return;
                    
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: 'muc_check_progress',
                            nonce: mucSettings.nonce
                        },
                        success: function(response) {
                            if (response.is_checking) {
                                setTimeout(checkProgress, 2000);
                            } else {
                                location.reload();
                            }
                        }
                    });
                }
                
                // Iniciar verificación cuando se hace clic en el botón
                $('input[name="muc_force_check"]').click(function() {
                    isChecking = true;
                    $('.wrap').prepend('<div class="notice notice-info"><p>' + mucSettings.messages.checking + '</p></div>');
                    setTimeout(checkProgress, 2000);
                });
            });
        },

        initResponsiveTables: function() {
            $(window).on('resize', function() {
                $('.wp-list-table').each(function() {
                    const $table = $(this);
                    if (window.innerWidth < 782) {
                        if (!$table.hasClass('mobile-view')) {
                            $table.addClass('mobile-view');
                        }
                    } else {
                        $table.removeClass('mobile-view');
                    }
                });
            }).trigger('resize');
        }
    };

    $(document).ready(function() {
        MUC.init();
    });

})(jQuery);