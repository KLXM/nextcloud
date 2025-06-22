let currentPath = '/';
let selectedFiles = new Set();

function loadFiles(path = '/') {
    currentPath = path;
    selectedFiles.clear();
    updateToolbar();
    
    const fileList = document.getElementById('fileList');
    fileList.innerHTML = '<tr><td colspan="6" class="text-center"><i class="rex-icon fa-spinner fa-spin"></i></td></tr>';
    
    const params = {
        page: 'nextcloud/main',
        nextcloud_api: '1',
        action: 'list',
        path: path
    };
    
    const url = 'index.php?' + $.param(params);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateBreadcrumb(path);
                renderFiles(data.data);
            } else {
                throw new Error(data.error || 'Unknown error occurred');
            }
        })
        .catch(error => {
            const errorMsg = document.createElement('tr');
            errorMsg.innerHTML = `<td colspan="6" class="alert alert-danger">${error.message}</td>`;
            fileList.innerHTML = '';
            fileList.appendChild(errorMsg);
            alert('Fehler: ' + error.message);
        });
}

function renderFiles(files) {
    const fileList = document.getElementById('fileList');
    fileList.innerHTML = '';
    
    if (currentPath !== '/') {
        const parentPath = currentPath.split('/').slice(0, -1).join('/') || '/';
        fileList.innerHTML += `
            <tr class="folder-row" style="cursor: pointer;" data-path="${parentPath}">
                <td style="width: 30px;"></td>
                <td><i class="rex-icon fa-level-up"></i></td>
                <td colspan="3">..</td>
                <td></td>
            </tr>`;
    }
    
    files.sort((a, b) => {
        if (a.type === 'folder' && b.type !== 'folder') return -1;
        if (a.type !== 'folder' && b.type === 'folder') return 1;
        return decodeURIComponent(a.name).localeCompare(decodeURIComponent(b.name));
    });
    
    files.forEach(file => {
        const icon = getFileIcon(file.type);
        const rowClass = file.type === 'folder' ? 'folder-row' : '';
        const decodedName = decodeURIComponent(file.name);
        
        // Checkbox nur für Dateien, nicht für Ordner
        const checkbox = file.type !== 'folder' 
            ? `<input type="checkbox" class="file-select" data-path="${file.path}" style="transform: scale(1.2);"${selectedFiles.has(file.path) ? ' checked' : ''}>`
            : '';
        
        // Name mit oder ohne Link für Bildvorschau/PDF-Vorschau und Word-Break
        const nameContent = file.type === 'image' 
            ? `<a href="#" onclick="event.stopPropagation(); previewImage('${file.path}', '${decodedName}'); return false;" style="word-break: break-word;">${decodedName}</a>`
            : file.type === 'pdf'
            ? `<a href="#" onclick="event.stopPropagation(); previewPdf('${file.path}', '${decodedName}'); return false;" style="word-break: break-word;">${decodedName}</a>`
            : `<span style="word-break: break-word;">${decodedName}</span>`;
            
        fileList.innerHTML += `
            <tr class="${rowClass}" ${file.type === 'folder' ? 'data-path="' + file.path + '"' : ''} style="${file.type === 'folder' ? 'cursor: pointer;' : ''}">
                <td style="width: 30px; text-align: center; vertical-align: middle;">
                    ${checkbox}
                </td>
                <td style="width: 50px; text-align: center; vertical-align: middle;">
                    ${file.type === 'image' 
                        ? `<a href="#" onclick="event.stopPropagation(); previewImage('${file.path}', '${decodedName}'); return false;"><i class="rex-icon ${icon}"></i></a>` 
                        : file.type === 'pdf'
                        ? `<a href="#" onclick="event.stopPropagation(); previewPdf('${file.path}', '${decodedName}'); return false;"><i class="rex-icon ${icon}"></i></a>`
                        : `<i class="rex-icon ${icon}"></i>`}
                </td>
                <td style="max-width: 500px; vertical-align: middle;">${nameContent}</td>
                <td style="width: 100px; vertical-align: middle;">${file.size || ''}</td>
                <td style="width: 150px; vertical-align: middle;">${file.modified || ''}</td>
                <td style="width: 60px; vertical-align: middle;">
                    ${file.type !== 'folder' ? `
                        <button class="btn btn-primary btn-xs" onclick="event.stopPropagation(); importFile('${file.path}')">
                            <i class="rex-icon fa-upload"></i>
                        </button>
                    ` : `
                        <button class="btn btn-default btn-xs">
                            <i class="rex-icon fa-chevron-right"></i>
                        </button>
                    `}
                </td>
            </tr>`;
    });

    // Event-Handler für Ordner-Klicks bleiben gleich
    $('.folder-row').on('click', function() {
        const path = $(this).data('path');
        if (path) {
            loadFiles(path);
        }
    });

    // Event-Handler für Checkboxen bleiben gleich
    $('.file-select').on('change', function(e) {
        e.stopPropagation();
        const path = $(this).data('path');
        if (this.checked) {
            selectedFiles.add(path);
        } else {
            selectedFiles.delete(path);
        }
        updateToolbar();
    });
}

function updateToolbar() {
    // Aktualisiere den Import-Button im Header basierend auf der Auswahl
    const headerButtons = $('.panel-heading .btn-group');
    const importButton = headerButtons.find('#btnImportSelected');
    
    if (selectedFiles.size > 0) {
        if (!importButton.length) {
            headerButtons.prepend(`
                <button class="btn btn-primary btn-xs" id="btnImportSelected" style="margin-right: 10px;">
                    <i class="rex-icon fa-upload"></i> ${selectedFiles.size} importieren
                </button>
            `);
            $('#btnImportSelected').on('click', importSelectedFiles);
        } else {
            importButton.html(`<i class="rex-icon fa-upload"></i> ${selectedFiles.size} importieren`);
        }
    } else {
        importButton.remove();
    }
}

async function importSelectedFiles() {
    const categoryId = $('#rex-mediapool-category').val();
    let imported = 0;
    let failed = [];

    // Progress Modal
    const modal = $(`
        <div class="modal fade" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title">Importiere Dateien...</h4>
                    </div>
                    <div class="modal-body">
                        <div class="progress">
                            <div class="progress-bar" role="progressbar" style="width: 0%;">0%</div>
                        </div>
                        <div id="import-status" class="text-center" style="margin-top: 10px;"></div>
                    </div>
                </div>
            </div>
        </div>
    `);
    
    modal.modal({backdrop: 'static', keyboard: false});

    const files = Array.from(selectedFiles);
    const total = files.length;
    let processed = 0;

    for (const path of files) {
        const fileName = decodeURIComponent(path.split('/').pop());
        
        try {
            modal.find('#import-status').text(
                `Importiere "${fileName}" (${processed + 1} von ${total})`
            );

            const params = {
                page: 'nextcloud/main',
                nextcloud_api: '1',
                action: 'import',
                path: path,
                category_id: categoryId
            };
            
            const url = 'index.php?' + $.param(params);
            
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                imported++;
            } else {
                failed.push({
                    name: fileName,
                    error: data.error || 'Unbekannter Fehler'
                });
            }

            // Kleine Pause zwischen den Importen
            await new Promise(resolve => setTimeout(resolve, 500));

        } catch (error) {
            failed.push({
                name: fileName,
                error: error.message
            });
        }

        processed++;
        const progress = Math.round((processed / total) * 100);
        modal.find('.progress-bar')
            .css('width', progress + '%')
            .text(progress + '%');
    }

    // Fertig
    setTimeout(() => {
        modal.modal('hide');
        
        // Detaillierte Zusammenfassung
        if (failed.length > 0) {
            let message = `Import abgeschlossen:\n\n`;
            message += `${imported} Dateien erfolgreich importiert\n`;
            message += `${failed.length} Fehler:\n\n`;
            failed.forEach(({name, error}) => {
                message += `- ${name}: ${error}\n`;
            });
            alert(message);
        } else {
            alert(`Alle ${imported} Dateien wurden erfolgreich importiert.`);
        }
        
        loadFiles(currentPath);
    }, 500);
}

function getFileIcon(type) {
    switch(type) {
        case 'folder': return 'fa-folder-o';
        case 'image': return 'fa-file-image-o';
        case 'pdf': return 'fa-file-pdf-o';
        case 'document': return 'fa-file-text-o';
        case 'archive': return 'fa-file-archive-o';
        case 'audio': return 'fa-file-audio-o';
        case 'video': return 'fa-file-video-o';
        default: return 'fa-file-o';
    }
}

function previewImage(path, name) {
    const params = {
        page: 'nextcloud/main',
        nextcloud_api: '1',
        action: 'preview',
        path: path
    };
    
    const previewUrl = 'index.php?' + $.param(params);
    
    const modal = $(`
        <div class="modal fade" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title">${name}</h4>
                    </div>
                    <div class="modal-body text-center">
                        <img src="${previewUrl}" style="max-width: 100%; max-height: 70vh;" alt="${name}">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Schließen</button>
                        <button type="button" class="btn btn-primary" onclick="importFile('${path}')">
                            <i class="rex-icon fa-upload"></i> Importieren
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `);

    modal.modal('show');
    modal.on('hidden.bs.modal', function() {
        modal.remove();
    });
}

function previewPdf(path, name) {
    const params = {
        page: 'nextcloud/main',
        nextcloud_api: '1',
        action: 'pdf_preview',
        path: path
    };
    
    const previewUrl = 'index.php?' + $.param(params);
    
    // Open PDF in new window
    window.open(previewUrl, '_blank');
}

function importFile(path) {
    const categoryId = $('#rex-mediapool-category').val();
    const fileName = decodeURIComponent(path.split('/').pop());
    
    const params = {
        page: 'nextcloud/main',
        nextcloud_api: '1',
        action: 'import',
        path: path,
        category_id: categoryId
    };
    
    const url = 'index.php?' + $.param(params);
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Datei erfolgreich importiert');
                loadFiles(currentPath);
                // Wenn Modal offen ist, schließen
                $('.modal').modal('hide');
            } else {
                throw new Error(data.error || 'Import failed');
            }
        })
        .catch(error => {
            alert(`Fehler beim Import von "${fileName}": ${error.message}`);
        });
}

function updateBreadcrumb(path) {
    const parts = path.split('/').filter(Boolean);
    let currentBuildPath = '';
    let breadcrumb = '<i class="rex-icon fa-home"></i> ';
    
    if (parts.length > 0) {
        breadcrumb += `<a href="#" onclick="loadFiles('/'); return false;">/</a> `;
        
        parts.forEach((part, index) => {
            currentBuildPath += '/' + part;
            const isLast = index === parts.length - 1;
            
            breadcrumb += isLast 
                ? `/ ${decodeURIComponent(part)} `
                : `/ <a href="#" onclick="loadFiles('${currentBuildPath}'); return false;">${decodeURIComponent(part)}</a> `;
        });
    }
    
    document.getElementById('pathBreadcrumb').innerHTML = breadcrumb;
}

$(document).on('rex:ready', function() {
    $('#btnRefresh').on('click', function() {
        loadFiles(currentPath);
    });
    
    $('#btnHome').on('click', function() {
        loadFiles('/');
    });
    
    loadFiles('/');
});