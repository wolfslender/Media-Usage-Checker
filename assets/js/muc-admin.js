
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all');
    if(selectAll) {
        selectAll.addEventListener('click', function(event) {
            let checkboxes = document.querySelectorAll('input[name="selected_media[]"]');
            for (let checkbox of checkboxes) {
                checkbox.checked = event.target.checked;
            }
        });
    }
});