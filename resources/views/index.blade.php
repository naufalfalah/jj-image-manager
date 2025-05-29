<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Image Manager</title>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>Jome Image Manager</h1>
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

    <div class="toast" id="toast"></div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/axios/0.21.1/axios.min.js"></script>
    <script>
        // Configuration
        const AWS_BUCKET = '{{ $awsBucket }}'; // Replace with your actual S3 bucket
        
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
                    folders.push({
                        name: domain,
                        images: [],
                        createdAt: new Date().toISOString()
                    });
                    input.value = '';
                    renderFolders();
                    selectFolder(domain);
                    showToast(`Folder "${domain}" created successfully`);
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
                    <span class="folder-name">${domain}</span>
                    <span class="folder-count">${folder.image_count ?? 0} images</span>
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
                <div class="upload-area" id="uploadArea" 
                     ondrop="handleDrop(event)" 
                     ondragover="handleDragOver(event)" 
                     ondragleave="handleDragLeave(event)">
                    <h3 class="upload-title">Upload Images to ${currentFolder}</h3>
                    <p class="upload-subtitle">Drag and drop images here or click to select</p>
                    <input type="file" id="fileInput" class="file-input" 
                           accept="image/*" multiple onchange="handleFileSelect(event)">
                    <label for="fileInput" class="choose-images-btn">Choose Images</label>
                    <p class="s3-info">Images will be uploaded to: s3://${AWS_BUCKET}/${currentFolder}/</p>
                </div>
                
                <div class="images-section">
                    <h3 class="section-title">Uploaded Images</h3>
                </div>
                ${folder.images_count === 0 ? 
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
            if (!currentFolder) {
                showToast('Please select a domain folder first', 'error');
                return;
            }

            const formData = new FormData();
            Array.from(files).forEach(file => {
                formData.append('files[]', file);
            });
            formData.append('domain_name', currentFolder);
            $.ajax({
                url: '/api/domains/images',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        const images = response.images;
                        const imageGrid = document.querySelector('.image-grid');
                        response.images.forEach(image => {
                            const imageCardHtml = createImageCard(image);
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = imageCardHtml;
                            const imageCardElement = tempDiv.firstElementChild;
                            imageGrid.prepend(imageCardElement);
                        });
                        showToast('Images uploaded successfully');
                    } else {
                        showToast('Error uploading images', 'error');
                    }
                },
                error: function(error) {
                    console.error('Error uploading images:', error);
                    showToast('Error uploading images', 'error');
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