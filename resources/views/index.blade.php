<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Image Manager</title>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>Image Manager</h1>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-danger">Logout</button>
            </form>
        </div>
    </div>

    <div class="container">
        <div class="sidebar">
            <h2 class="sidebar-title">Domain Folders</h2>
            <div class="folder-input-group">
                <input
                    type="text"
                    class="folder-input"
                    id="domainInput"
                    name="name"
                    placeholder="Enter domain name"
                    onkeypress="handleKeyPress(event)"
                >
                <button class="add-folder-btn" onclick="createDomain()">+</button>
            </div>
            <div class="folder-list" id="folderList"></div>
        </div>

        <div class="main-content" id="mainContent">
            <div class="welcome-message">
                <h2>Welcome to Domain Image Manager</h2>
                <p>Create a domain folder to start uploading images</p>
            </div>
        </div>
    </div>

    <div id="editFolderModal" class="modal-overlay">
        <div class="modal-content">
            <h3>Edit Folder</h3>
            <input type="text" id="editFolderInput" style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db;font-size:15px;margin-bottom:18px;margin-top:4px;background:#f9fafb;">
            <div style="margin-top:16px; text-align:right;">
                <button onclick="closeEditFolderModal()">Cancel</button>
                <button id="editFolderActionBtn">Edit</button>
            </div>
        </div>
    </div>

    <div id="cloneDomainModal" class="modal-overlay">
        <div class="modal-content">
            <h3>Clone Folder</h3>
            <input type="text" id="cloneDomainInput" placeholder="New domain name">
            <div style="margin-top:16px; text-align:right;">
                <button onclick="closeCloneDomainModal()">Cancel</button>
                <button id="cloneDomainActionBtn">Clone</button>
            </div>
        </div>
    </div>

    <div id="deleteFolderModal" class="modal-overlay">
        <div class="modal-content">
            <h3>Delete Folder</h3>
            <p>Are you sure you want to delete the folder "<span id="deleteFolderName"></span>"?</p>
            <div style="margin-top:16px; text-align:right;">
                <button onclick="closeDeleteDomainModal()">Cancel</button>
                <button id="confirmDeleteFolderBtn">Delete</button>
            </div>
        </div>
    </div>

    <div id="copyMoveModal" class="modal-overlay">
        <div class="modal-content">
            <h3 id="copyMoveTitle"></h3>
            <select id="targetDomainSelect"></select>
            <div style="margin-top:16px; text-align:right;">
                <button onclick="closeCopyMoveModal()">Cancel</button>
                <button id="copyMoveActionBtn">OK</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/axios/0.21.1/axios.min.js"></script>
    <script>
        // Configuration
        const AWS_BUCKET = '{{ $awsBucket }}';
        const AWS_REGION = '{{ $awsRegion }}';

        // State management
        let folders = [];
        let currentFolder = null;
        let uploadIdCounter = 1;

        // Initialize the app
        function init() {
            fetchDomains();
        }

        // Handle Enter key press
        function handleKeyPress(e) {
            if (e.key === 'Enter') {
                createDomain();
            }
        }

        // Fetch domains
        function fetchDomains() {
            $.ajax({
                url: '/api/domains',
                method: 'GET',
                success: function(response) {
                    folders = response.domains;
                    renderFolders();
                },
                error: function(error) {
                    console.error('Error fetching folders:', error);
                }
            });
        }

        // Handle folder creation
        function createDomain() {
            const input = document.getElementById('domainInput');
            const domain = input.value.trim();

            if (!domain) {
                showToast('Please enter a domain name', 'error');
                return;
            }

            if (folders.some(folder => folder.name === domain)) {
                showToast('This domain folder already exists', 'error');
                return;
            }

            // Simulate API call to create domain
            $.ajax({
                url: '/api/domains',
                method: 'POST',
                data: { name: domain },
                success: function(response) {
                    if (response.success) {
                        folders.push({
                            name: response.domain.name,
                            images: [],
                            createdAt: new Date().toISOString()
                        });
                        input.value = '';
                        renderFolders();
                        selectFolder(domain);
                        showToast(`Folder "${domain}" created successfully`);
                    } else {
                        showToast('Error creating folder', 'error');
                        return;
                    }
                },
                error: function(error) {
                    console.error('Error creating folder:', error);
                    showToast('Error creating folder', 'error');
                }
            });
        }

        // Create new folder
        function createFolder() {
            const input = document.getElementById('domainInput');
            const domain = input.value.trim();

            if (!domain) {
                showToast('Please enter a domain name', 'error');
                return;
            }

            if (folders[domain]) {
                showToast('This domain folder already exists', 'error');
                return;
            }

            folders[domain] = {
                name: domain,
                images: [],
                createdAt: new Date().toISOString()
            };

            input.value = '';

            renderFolders();
            selectFolder(domain);
            showToast(`Folder "${domain}" created successfully`);
        }

        // Render folder list
        function renderFolders() {
            const folderList = document.getElementById('folderList');
            folderList.innerHTML = '';

            folders.map(folder => {
                const domain = folder.name;
                const folderEl = document.createElement('div');
                folderEl.className = `folder-item ${currentFolder === domain ? 'active' : ''}`;
                folderEl.onclick = () => selectFolder(domain);

                folderEl.innerHTML = `
                    <div class="folder-row-1">
                        <span class="folder-name" title="${domain}">${domain}</span>
                    </div>
                    <div class="folder-row-2">
                        <span class="folder-count">${folder.images_count ?? 0} images</span>

                        <button class="edit-folder-btn" onclick="editFolder(event, '${domain}', ${folder.id})" title="Rename Folder">
                            <i class="fas fa-edit"></i>
                        </button>
                        ${folder.images_count ?
                        `<button class="clone-folder-btn" onclick="showCloneDomainModal(${folder.id}, '${domain}')">
                            <i class="fas fa-copy"></i>
                        </button>` : ``}
                        <button class="delete-folder-btn" onclick="showDeleteDomainModal(${folder.id}, '${domain}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;

                folderList.appendChild(folderEl);
            });
        }

        // Select a folder
        function selectFolder(domain) {
            currentFolder = domain;
            renderFolders();
            renderMainContent();
        }

        // Edit folder name
        let currentEditFolderId = null;
        let currentEditFolderOldName = null;

        function editFolder(event, oldDomain, domainId) {
            event.stopPropagation();
            currentEditFolderId = domainId;
            currentEditFolderOldName = oldDomain;
            document.getElementById('editFolderInput').value = oldDomain;
            document.getElementById('editFolderModal').style.display = 'flex';
            document.getElementById('editFolderActionBtn').onclick = doEditFolder;
        }

        function closeEditFolderModal() {
            document.getElementById('editFolderModal').style.display = 'none';
        }

        function doEditFolder() {
            const newDomain = document.getElementById('editFolderInput').value.trim();
            if (!newDomain || newDomain === currentEditFolderOldName) {
                closeEditFolderModal();
                return;
            }

            $.ajax({
                url: `/api/domains/${currentEditFolderId}`,
                method: 'PUT',
                data: { name: newDomain },
                success: function(response) {
                    showToast('Folder renamed successfully');

                    currentFolder = null;
                    fetchDomains();
                    renderMainContent();
                    closeEditFolderModal();
                },
                error: function(error) {
                    let errorMessage = 'Error renaming folder';
                    if (error.responseJSON && error.responseJSON.message) {
                        errorMessage = error.responseJSON.message;
                    }

                    showToast(errorMessage, 'error');
                    closeEditFolderModal();
                }
            });
        }

        // Clone domain
        let currentCloneDomainId = null;
        let currentCloneDomainOldName = null;

        function showCloneDomainModal(domainId, oldDomain) {
            currentCloneDomainId = domainId;
            currentCloneDomainOldName = oldDomain;
            document.getElementById('cloneDomainInput').value = '';
            document.getElementById('cloneDomainModal').style.display = 'flex';
            document.getElementById('cloneDomainActionBtn').onclick = doCloneDomain;
        }

        function closeCloneDomainModal() {
            document.getElementById('cloneDomainModal').style.display = 'none';
        }

        function doCloneDomain() {
            const newDomain = document.getElementById('cloneDomainInput').value.trim();
            if (!newDomain) {
                showToast('Please enter a new domain name', 'error');
                return;
            }
            $.ajax({
                url: `/api/domains/${currentCloneDomainId}/clone`,
                method: 'POST',
                data: { name: newDomain },
                success: function(response) {
                    closeCloneDomainModal();
                    showToast('Domain cloned successfully');
                    fetchDomains();
                },
                error: function(error) {
                    let errorMessage = 'Failed to clone domain';
                    if (error.responseJSON && error.responseJSON.message) {
                        errorMessage = error.responseJSON.message;
                    }

                    showToast(errorMessage, 'error');
                    closeCloneDomainModal();
                }
            });
        }

        // Delete folder
        let currentDeleteFolderId = null;
        let currentDeleteFolderName = null;

        function showDeleteDomainModal(domainId, domain) {
            currentDeleteFolderId = domainId;
            currentDeleteFolderName = domain;
            document.getElementById('deleteFolderName').textContent = domain;
            document.getElementById('confirmDeleteFolderBtn').onclick = deleteFolder;
            document.getElementById('deleteFolderModal').style.display = 'flex';
        }

        function closeDeleteDomainModal() {
            const modal = document.getElementById('deleteFolderModal');
            modal.style.display = 'none';
        }

        function deleteFolder(domainId, domain) {
            if (!currentDeleteFolderId) return;

            $.ajax({
                url: `/api/domains/${currentDeleteFolderId}`,
                method: 'DELETE',
                success: function(response) {
                    currentFolder = null;
                    fetchDomains();
                    renderMainContent();
                    showToast(`Folder deleted successfully`);
                    closeDeleteDomainModal();
                },
                error: function(error) {
                    let errorMessage = 'Failed to delete domain';
                    if (error.responseJSON && error.responseJSON.message) {
                        errorMessage = error.responseJSON.message;
                    }

                    showToast(errorMessage, 'error');
                    closeDeleteDomainModal();
                }
            });
        }

        // Render main content area
        function renderMainContent() {
            const mainContent = document.getElementById('mainContent');

            if (!currentFolder) {
                mainContent.innerHTML = `
                    <div class="welcome-message">
                        <h2>Welcome to Domain Image Manager</h2>
                        <p>Create a domain folder to start uploading images</p>
                    </div>
                `;
                return;
            }

            const folder = folders.find(f => f.name === currentFolder);

            mainContent.innerHTML = `
                <div class="folder-header">
                    <h2 class="folder-title">${currentFolder}</h2>
                </div>
                <div class="upload-area" id="uploadArea"
                     ondrop="handleDrop(event)"
                     ondragover="handleDragOver(event)"
                     ondragleave="handleDragLeave(event)">
                    <h3 class="upload-title">Upload Images to ${currentFolder}</h3>
                    <p class="upload-subtitle">Drag and drop images here or click to select</p>
                    <input type="file" id="fileInput" class="file-input"
                           accept="image/*" multiple onchange="handleFileSelect(event)">
                    <label for="fileInput" class="choose-images-btn">Choose Images</label>
                    <p class="s3-info">Images will be uploaded to: https:///${currentFolder}.s3.${AWS_REGION}.amazon.aws.com/images/</p>
                </div>

                <div class="images-section">
                    <h3 class="section-title">Uploaded Images</h3>
                </div>
                ${!folder.images_count || folder.images_count === 0 ?
                    '<div class="empty-images">No images uploaded yet</div>' : ''
                }
                <div class="image-grid">
                </div>
            `;

            if (folder.images_count !== 0) {
                fetchDomainImages(currentFolder);
            }
        }

        function fetchDomainImages(domain) {
            $.ajax({
                url: `/api/domains/images`,
                method: 'GET',
                data: { domain_name: domain },
                success: function(response) {
                    const images = response.images;
                    const imageGrid = document.querySelector('.image-grid');

                    imageGrid.innerHTML += images.map(createImageCard).join('');
                },
                error: function(error) {
                    console.error('Error fetching images:', error);
                    showToast('Error fetching images', 'error');
                }
            });
        }

        // Create image card HTML
        function createImageCard(image) {
            return `
                <div class="image-card">
                    <img src="${image.url}" alt="${image.name}" class="image-preview">
                    <div class="image-details">
                        <div class="image-filename">${image.name}</div>
                        <div class="image-url">${image.url}</div>
                        <button class="copy-btn" onclick="copyUrl('${image.url}', this)">
                            Copy URL
                        </button>
                        <div class="button-group">
                            <button class="edit-btn" onclick="editImage(${image.id})">
                                Edit
                            </button>
                            <button class="delete-btn" onclick="deleteImage(${image.id}, this)">
                                Delete
                            </button>
                        </div>
                        <div class="button-group">
                            <button class="copy-image-btn" onclick="showCopyMoveModal(${image.id}, 'copy')">
                                Copy Image
                            </button>
                            <button class="move-image-btn" onclick="showCopyMoveModal(${image.id}, 'move', this)">
                                Move Image
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        // Handle file selection
        function handleFileSelect(event) {
            const files = event.target.files;
            processFiles(files);
        }

        // Drag and drop handlers
        function handleDragOver(event) {
            event.preventDefault();
            event.stopPropagation();
            document.getElementById('uploadArea').classList.add('dragover');
        }

        function handleDragLeave(event) {
            event.preventDefault();
            event.stopPropagation();
            document.getElementById('uploadArea').classList.remove('dragover');
        }

        function handleDrop(event) {
            event.preventDefault();
            event.stopPropagation();
            document.getElementById('uploadArea').classList.remove('dragover');

            const files = event.dataTransfer.files;
            processFiles(files);
        }

        function processFiles(files) {
            if (!files || files.length === 0) {
                showToast('No files selected', 'error');
                return;
            }

            const formData = new FormData();
            Array.from(files).forEach(file => {
                formData.append('files[]', file);
            });
            formData.append('domain_name', currentFolder);

            let progressBar = document.getElementById('uploadProgressBar');
            if (!progressBar) {
                progressBar = document.createElement('div');
                progressBar.id = 'uploadProgressBar';
                progressBar.style.width = '0%';
                progressBar.style.height = '6px';
                progressBar.style.background = '#38bdf8';
                progressBar.style.transition = 'width 0.2s';
                progressBar.style.marginBottom = '10px';
                document.getElementById('uploadArea').prepend(progressBar);
            }

            axios.post('/api/domains/images', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
                onUploadProgress: function(progressEvent) {
                    if (progressEvent.lengthComputable) {
                        const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                        progressBar.style.width = percent + '%';
                    }
                }
            })
            .then(function(response) {
                progressBar.style.width = '100%';
                setTimeout(() => progressBar.remove(), 800);

                if (response.data.success) {
                    const emptyImages = document.querySelector('.empty-images');
                    if (emptyImages) {
                        emptyImages.remove();
                    }

                    const images = response.data.images;
                    const imageGrid = document.querySelector('.image-grid');
                    images.forEach(image => {
                        const imageCardHtml = createImageCard(image);
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = imageCardHtml;
                        const imageCardElement = tempDiv.firstElementChild;
                        imageGrid.prepend(imageCardElement);
                    });

                    const folder = folders.find(f => f.name === currentFolder);
                    if (folder) {
                        folder.images_count = (folder.images_count || 0) + images.length;
                        renderFolders();
                    }
                    showToast('Images uploaded successfully');
                } else {
                    showToast('Error uploading images', 'error');
                }
            })
            .catch(function(error) {
                progressBar.remove();
                showToast('Error uploading images', 'error');
            });
        }

        function editImage(imageId) {
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.onchange = function(event) {
                const files = event.target.files;
                if (!files || files.length === 0) {
                    showToast('No file selected', 'error');
                    return;
                }
                const formData = new FormData();
                formData.append('files[]', files[0]);

                $.ajax({
                    url: `/api/domains/images/${imageId}`,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            const imageGrid = document.querySelector('.image-grid');
                            imageGrid.innerHTML = '';
                            fetchDomainImages(currentFolder);
                            showToast('Image updated successfully. Thumbnails refresh will take a moment.');
                        } else {
                            showToast('Error uploading images', 'error');
                        }
                    },
                    error: function(error) {
                        showToast('Error updating image', 'error');
                    }
                });
            };
            fileInput.click();
        }

        function deleteImage(imageId, btn) {
            if (!confirm('Are you sure you want to delete this image?')) return;

            $.ajax({
                url: `/api/domains/images/${imageId}`,
                method: 'DELETE',
                success: function(response) {
                    showToast('Image deleted successfully');
                    const card = btn.closest('.image-card');
                    if (card) card.remove();

                    const folder = folders.find(f => f.name === currentFolder);
                    if (folder && folder.images_count > 0) {
                        folder.images_count -= 1;
                        renderFolders();
                    }
                },
                error: function(error) {
                    showToast('Error deleting image', 'error');
                }
            });
        }

        // Copy/Move Image
        let currentCopyMoveImageId = null;
        let currentCopyMoveAction = null;
        let currentImageCard = null;

        function showCopyMoveModal(imageId, action, btn = null) {
            currentCopyMoveImageId = imageId;
            currentCopyMoveAction = action;
            currentImageCard = btn ? btn.closest('.image-card') : null;
            document.getElementById('copyMoveTitle').textContent = (action === 'copy' ? 'Copy' : 'Move') + ' Image to Domain';
            const select = document.getElementById('targetDomainSelect');
            select.innerHTML = folders
                .filter(f => f.name !== currentFolder)
                .map(f => `<option value="${f.name}">${f.name}</option>`)
                .join('');
            document.getElementById('copyMoveModal').style.display = 'flex';
            document.getElementById('copyMoveActionBtn').onclick = doCopyMoveImage;
        }

        function closeCopyMoveModal() {
            document.getElementById('copyMoveModal').style.display = 'none';
        }

        function doCopyMoveImage() {
            const targetDomain = document.getElementById('targetDomainSelect').value;
            if (!targetDomain) return;

            $.ajax({
                url: `/api/domains/images/${currentCopyMoveImageId}/${currentCopyMoveAction}`,
                method: 'POST',
                data: { target_domain_name: targetDomain },
                success: function(response) {
                    closeCopyMoveModal();
                    showToast(`Image ${currentCopyMoveAction}d successfully`);

                    // Refresh image grid & sidebar count
                    if (currentCopyMoveAction === 'move') {
                        const folder = folders.find(f => f.name === currentFolder);
                        if (folder && folder.images_count > 0) {
                            folder.images_count -= 1;
                            renderFolders();
                        }
                        const card = currentImageCard.closest('.image-card');
                        if (card) card.remove();
                    }

                    const targetFolder = folders.find(f => f.name === targetDomain);
                    console.log('targetFolder', targetFolder);
                    if (targetFolder && !response.overwrite) {
                        targetFolder.images_count = (targetFolder.images_count || 0) + 1;
                        renderFolders();
                    }
                },
                error: function(error) {
                    let errorMessage = 'Failed to copy image';
                    if (error.responseJSON && error.responseJSON.message) {
                        errorMessage = error.responseJSON.message;
                    }

                    showToast(errorMessage, 'error');
                    closeCopyMoveModal();
                }
            });
        }

        // Copy URL to clipboard
        function copyUrl(url, button) {
            // Create temporary textarea
            const textarea = document.createElement('textarea');
            textarea.value = url;
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);

            // Select and copy
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);

            // Update button state
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            button.classList.add('copied');

            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('copied');
            }, 2000);

            showToast('URL copied to clipboard', 'success');
        }

        // Show toast notification
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Initialize app on load
        init();
    </script>
</body>
</html>
