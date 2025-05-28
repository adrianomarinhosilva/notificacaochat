/**
 * Stub para o plugin resourceTimeline
 * Apenas para evitar erros no console
 */
(function() {
    // Verifica se jQuery existe
    if (typeof jQuery !== 'undefined') {
        // Cria um plugin stub para o FullCalendar
        if (typeof jQuery.fn.fullCalendar !== 'undefined') {
            jQuery.fn.fullCalendar.resourceTimelinePlugin = {
                name: 'resourceTimeline',
                initialize: function() {},
                recurringTypes: []
            };
            console.log('Stub para resourceTimeline carregado com sucesso');
        }
    }
})();