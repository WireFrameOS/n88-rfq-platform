/**
 * N88 RFQ Platform - Phase 2A Frontend Extensions
 * Modal, Comments, Quotes, Item Cards, and Notifications
 */

(function() {
    'use strict';
    
    console.log('N88 RFQ Modal JS: Script loaded');

    // Modal Manager
    const N88Modal = {
        currentModal: null,
        currentProjectId: null,

        // Open project details modal
        openProjectModal: function(projectId) {
            this.currentProjectId = projectId;
            this.currentModal = 'project-detail';
            
            const modal = document.getElementById('n88-project-modal');
            if (!modal) return;

            // Fetch project data via AJAX
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_modal',
                    project_id: projectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.renderProjectModal(data.data);
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            })
            .catch(error => console.error('Error loading project:', error));
        },

        // Render modal tabs
        renderProjectModal: function(project) {
            const modal = document.getElementById('n88-project-modal');
            const header = modal.querySelector('.n88-modal-header');
            
            // Determine status badge
            let statusText = 'Draft';
            let statusBadgeClass = 'n88-status-draft';
            let statusBadgeText = 'Draft';
            
            if (project.status == 1) {
                // Check project status progression
                if (project.production_status === 'completed') {
                    statusText = 'Completed';
                    statusBadgeClass = 'n88-status-completed';
                    statusBadgeText = 'Completed';
                } else if (project.production_status === 'in_production') {
                    statusText = 'In Production';
                    statusBadgeClass = 'n88-status-production';
                    statusBadgeText = 'In Production';
                } else if (project.quote_status === 'sent') {
                    statusText = 'Quoted';
                    statusBadgeClass = 'n88-status-quoted';
                    statusBadgeText = 'Quoted';
                } else {
                    statusText = 'Needs Quote';
                    statusBadgeClass = 'n88-status-submitted';
                    statusBadgeText = 'Needs Quote';
                }
            }
            
            const updatedDate = project.updated_at ? new Date(project.updated_at) : null;
            const updatedDateFormatted = updatedDate 
                ? updatedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + ' – ' + updatedDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
                : 'N/A';
            const updatedByName = project.updated_by_name || (project.updated_by_user_id ? 'Admin' : 'Client');
            const createdDate = project.created_at ? new Date(project.created_at).toLocaleDateString() : 'N/A';
            
            // Update header title
            header.querySelector('h2').innerHTML = escapeHtml(project.project_name || 'Project');
            
            // Add/update Status Summary Block (between header and tabs)
            let statusSummary = modal.querySelector('.n88-status-summary-block');
            if (!statusSummary) {
                statusSummary = document.createElement('div');
                statusSummary.className = 'n88-status-summary-block';
                const tabs = modal.querySelector('.n88-modal-tabs');
                tabs.parentNode.insertBefore(statusSummary, tabs);
            }
            
            // Determine step info (optional - can be enhanced later)
            const stepInfo = this.getStepInfo(project);
            
            statusSummary.innerHTML = `
                <div class="n88-status-summary-content">
                    <div class="n88-status-badge ${statusBadgeClass}">${statusBadgeText}</div>
                    <div class="n88-status-meta">
                        <div class="n88-status-meta-item">
                            <span class="n88-meta-label">Last updated:</span>
                            <span class="n88-meta-value">${updatedDateFormatted}</span>
                        </div>
                        <div class="n88-status-meta-item">
                            <span class="n88-meta-label">Updated by:</span>
                            <span class="n88-meta-value">${escapeHtml(updatedByName)}</span>
                        </div>
                        ${stepInfo ? `
                        <div class="n88-status-meta-item">
                            <span class="n88-meta-label">Step:</span>
                            <span class="n88-meta-value">${stepInfo}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;

            // Set active tab to summary
            document.querySelectorAll('.n88-modal-tab').forEach(tab => {
                tab.classList.remove('active');
                tab.addEventListener('click', (e) => this.switchTab(e.target.dataset.tab));
            });
            document.querySelector('[data-tab="summary"]').classList.add('active');

            // Render summary tab first
            this.renderSummaryTab(project);
            
            // Also render items tab (for when user switches)
            this.renderItemsTab(null, project);
        },

        // Get step info for status summary (optional)
        getStepInfo: function(project) {
            // Check if timeline steps are available
            if (project.timeline_steps && Array.isArray(project.timeline_steps)) {
                const completedSteps = project.timeline_steps.filter(step => step.completed === true).length;
                const totalSteps = project.timeline_steps.length;
                const currentStep = project.timeline_steps.find(step => step.completed === false);
                
                if (currentStep && totalSteps > 0) {
                    const stepNumber = completedSteps + 1;
                    return `Step ${stepNumber} of ${totalSteps} – ${currentStep.name || 'In Progress'}`;
                } else if (totalSteps > 0 && completedSteps === totalSteps) {
                    return `Step ${totalSteps} of ${totalSteps} – Completed`;
                }
            }
            
            // Fallback to basic step info based on status
            if (project.status == 0) {
                return 'Step 1 of 6 – Draft';
            } else if (project.status == 1 && !project.quote_status) {
                return 'Step 2 of 6 – Awaiting Quote';
            } else if (project.quote_status === 'sent' && project.production_status !== 'in_production' && project.production_status !== 'completed') {
                return 'Step 3 of 6 – Quote Sent';
            } else if (project.production_status === 'in_production') {
                return 'Step 4 of 6 – In Production';
            } else if (project.production_status === 'completed') {
                return 'Step 6 of 6 – Completed';
            }
            return null;
        },

        // Render summary tab
        renderSummaryTab: function(project) {
            const container = document.getElementById('summary-content');
            if (!container) return;
            
            const statusText = project.status == 1 ? 'Submitted' : 'Draft';
            const updatedDate = project.updated_at ? new Date(project.updated_at).toLocaleString() : 'N/A';
            const updatedByName = project.updated_by_name || 'Unknown';
            const quoteType = project.quote_type || 'Not specified';
            const itemCount = project.item_count || 0;
            
            let html = `
                <div class="n88-summary-block">
                    <div class="n88-summary-grid">
                        <div class="n88-summary-item">
                            <label>Current Status</label>
                            <p><strong>${statusText}</strong></p>
                        </div>
                        <div class="n88-summary-item">
                            <label>Last Updated</label>
                            <p>${updatedDate}</p>
                        </div>
                        <div class="n88-summary-item">
                            <label>Updated By</label>
                            <p>${escapeHtml(updatedByName)}</p>
                        </div>
                        <div class="n88-summary-item">
                            <label>Quote Type</label>
                            <p>${escapeHtml(quoteType)}</p>
                        </div>
                        <div class="n88-summary-item">
                            <label>Item Counter</label>
                            <p><strong>${itemCount}</strong> items</p>
                        </div>
                        <div class="n88-summary-item">
                            <label>Timeline</label>
                            <p>${escapeHtml(project.timeline || 'N/A')}</p>
                        </div>
                        <div class="n88-summary-item">
                            <label>Budget Range</label>
                            <p>${escapeHtml(project.budget || 'N/A')}</p>
                        </div>
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
        },

        // Render items as cards with expand/collapse
        renderItemsTab: function(container, project) {
            const items = project.items || [];
            let html = '<div class="n88-items-cards">';

            items.forEach((item, index) => {
                // Use index as item ID if not provided (for backward compatibility)
                const itemId = item.id || index.toString();
                html += `
                    <div class="n88-item-card">
                        <div class="n88-item-header" onclick="N88Modal.toggleItemExpand(this)">
                            <h3>Item ${index + 1}</h3>
                            <span class="n88-expand-icon">+</span>
                        </div>
                        <div class="n88-item-content" style="display:none;">
                            <!-- 1. Primary Material -->
                            <div class="n88-item-field">
                                <label>Primary Material</label>
                                <p>${escapeHtml(item.primary_material || 'N/A')}</p>
                            </div>
                            
                            <!-- 2. Dimensions -->
                            <div class="n88-item-field">
                                <label>Dimensions (L × W × H)</label>
                                <p>${item.length_in || 0} × ${item.depth_in || 0} × ${item.height_in || 0}</p>
                            </div>
                            
                            <!-- 3. Quantity -->
                            <div class="n88-item-field">
                                <label>Quantity</label>
                                <p>${item.quantity || 0}</p>
                            </div>
                            
                            <!-- 4. Construction Notes -->
                            <div class="n88-item-field">
                                <label>Construction Notes</label>
                                <p>${escapeHtml(item.construction_notes || 'N/A')}</p>
                            </div>
                            
                            <!-- 5. Finishes -->
                            <div class="n88-item-field">
                                <label>Finishes</label>
                                <p>${escapeHtml(item.finishes || 'N/A')}</p>
                            </div>
                            
                            <!-- 6. Files -->
                            ${this.renderItemFiles(project.id, itemId)}
                            
                            <!-- 7. Notes -->
                            <div class="n88-item-field">
                                <label>Notes</label>
                                <p>${escapeHtml(item.notes || 'N/A')}</p>
                            </div>
                            
                            <!-- Additional optional fields -->
                            ${item.cushions ? `
                            <div class="n88-item-field">
                                <label>Cushions</label>
                                <p>${item.cushions}</p>
                            </div>
                            ` : ''}
                            ${item.fabric_category ? `
                            <div class="n88-item-field">
                                <label>Fabric Category</label>
                                <p>${escapeHtml(item.fabric_category)}</p>
                            </div>
                            ` : ''}
                            ${item.frame_material ? `
                            <div class="n88-item-field">
                                <label>Frame Material</label>
                                <p>${escapeHtml(item.frame_material)}</p>
                            </div>
                            ` : ''}
                            ${item.finish ? `
                            <div class="n88-item-field">
                                <label>Finish</label>
                                <p>${escapeHtml(item.finish)}</p>
                            </div>
                            ` : ''}
                            
                            <!-- Comments -->
                            ${this.renderItemComments(project.id, itemId)}
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            const contentContainer = document.getElementById('items-content');
            if (contentContainer) {
                contentContainer.innerHTML = html;
            }
        },

        // Toggle item card expansion
        toggleItemExpand: function(header) {
            const content = header.nextElementSibling;
            const icon = header.querySelector('.n88-expand-icon');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.textContent = '−';
            } else {
                content.style.display = 'none';
                icon.textContent = '+';
            }
        },

        // Render item comments
        renderItemComments: function(projectId, itemId) {
            return `
                <div class="n88-item-comments">
                    <h4>Comments</h4>
                    <div id="comments-${itemId}" class="n88-comments-list"></div>
                    <textarea class="n88-comment-input" placeholder="Add a comment..."></textarea>
                    <button class="btn btn-sm" onclick="N88Comments.addComment(${projectId}, '${itemId}', this)">Post Comment</button>
                </div>
            `;
        },

        // Render item files
        renderItemFiles: function(projectId, itemId) {
            return `
                <div class="n88-item-files">
                    <h4>Files (PDF/JPG/PNG/DWG)</h4>
                    <div id="files-${itemId}" class="n88-files-list"></div>
                    <div class="n88-file-uploader" ondrop="N88Files.handleDrop(event, ${projectId}, '${itemId}')" ondragover="N88Files.handleDragOver(event)">
                        <p>Drag files here or <a href="#" onclick="event.preventDefault(); document.querySelector('#file-input-${itemId}').click();">browse</a></p>
                        <input type="file" id="file-input-${itemId}" style="display:none;" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.dwg" onchange="N88Files.handleFileSelect(event, ${projectId}, '${itemId}')">
                    </div>
                </div>
            `;
        },

        // Switch modal tabs
        switchTab: function(tabName) {
            const tabs = document.querySelectorAll('.n88-modal-tab');
            const contents = document.querySelectorAll('.n88-modal-content-section');

            tabs.forEach(tab => tab.classList.remove('active'));
            contents.forEach(content => content.style.display = 'none');

            const activeTab = document.querySelector(`[data-tab="${tabName}"]`);
            const activeContent = document.getElementById(`${tabName}-content`);
            
            if (activeTab) activeTab.classList.add('active');
            if (activeContent) activeContent.style.display = 'block';

            // Load tab-specific data
            if (tabName === 'summary') {
                // Fetch project data for summary
                fetch(n88.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        action: 'n88_get_project_modal',
                        project_id: this.currentProjectId,
                        nonce: n88.nonce
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.renderSummaryTab(data.data);
                    }
                })
                .catch(error => console.error('Error loading summary:', error));
            } else if (tabName === 'items') {
                // Fetch project data for items
                fetch(n88.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        action: 'n88_get_project_modal',
                        project_id: this.currentProjectId,
                        nonce: n88.nonce
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.renderItemsTab(null, data.data);
                    }
                })
                .catch(error => console.error('Error loading items:', error));
            } else if (tabName === 'timeline') {
                this.renderTimelineTab();
            } else if (tabName === 'files') {
                this.renderFilesTab();
            } else if (tabName === 'comments') {
                N88Comments.loadProjectComments(this.currentProjectId);
            } else if (tabName === 'quote') {
                this.renderQuotePanel();
            } else if (tabName === 'notifications') {
                this.renderNotificationsTab();
            }
        },
        
        // Render timeline tab
        renderTimelineTab: function() {
            const container = document.getElementById('timeline-content');
            if (!container) return;
            
            // Fetch project data again for timeline
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_modal',
                    project_id: this.currentProjectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const project = data.data;
                    let html = `
                        <div class="n88-timeline-block">
                            <div class="n88-timeline-item">
                                <label>Project Timeline</label>
                                <p><strong>${escapeHtml(project.timeline || 'Not specified')}</strong></p>
                            </div>
                            <div class="n88-timeline-item">
                                <label>Created</label>
                                <p>${project.created_at ? new Date(project.created_at).toLocaleString() : 'N/A'}</p>
                            </div>
                            <div class="n88-timeline-item">
                                <label>Last Updated</label>
                                <p>${project.updated_at ? new Date(project.updated_at).toLocaleString() : 'N/A'}</p>
                            </div>
                            ${project.submitted_at ? `
                            <div class="n88-timeline-item">
                                <label>Submitted</label>
                                <p>${new Date(project.submitted_at).toLocaleString()}</p>
                            </div>
                            ` : ''}
                        </div>
                    `;
                    container.innerHTML = html;
                }
            })
            .catch(error => console.error('Error loading timeline:', error));
        },
        
        // Render notifications tab
        renderNotificationsTab: function() {
            const container = document.getElementById('notifications-content');
            if (!container) return;
            
            // Fetch project data again for notifications
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_modal',
                    project_id: this.currentProjectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const notifications = data.data.notifications || [];
                    let html = '<div class="n88-notifications-list">';
                    
                    if (notifications.length === 0) {
                        html += '<p>No notifications for this project.</p>';
                    } else {
                        notifications.forEach(notif => {
                            const formatted = N88_RFQ_Notifications.format_notification ? 
                                N88_RFQ_Notifications.format_notification(notif) : notif;
                            html += `
                                <div class="n88-notification-item ${notif.is_read ? 'read' : 'unread'}">
                                    <div class="n88-notification-header">
                                        <strong>${escapeHtml(formatted.notification_type || 'Notification')}</strong>
                                        <span class="n88-notification-time">${formatted.created_at || ''}</span>
                                    </div>
                                    <p>${escapeHtml(formatted.message || '')}</p>
                                </div>
                            `;
                        });
                    }
                    
                    html += '</div>';
                    container.innerHTML = html;
                }
            })
            .catch(error => console.error('Error loading notifications:', error));
        },

        // Render files tab
        renderFilesTab: function() {
            const container = document.getElementById('files-content');
            if (!container) return;
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_files',
                    project_id: this.currentProjectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const files = data.data || [];
                    let html = '<div class="n88-files-container">';
                    
                    if (files.length === 0) {
                        html += '<p class="n88-no-files">No files attached to this project yet.</p>';
                    } else {
                        html += '<div class="n88-files-grid">';
                        files.forEach(file => {
                            const fileSize = file.size ? (file.size / 1024).toFixed(2) : '0';
                            const fileIcon = this.getFileIcon(file.name || '');
                            html += `
                                <div class="n88-file-item">
                                    <div class="n88-file-icon">${fileIcon}</div>
                                    <div class="n88-file-info">
                                        <h4 class="n88-file-name">${escapeHtml(file.name || 'Untitled')}</h4>
                                        <p class="n88-file-meta">${fileSize} KB | ${escapeHtml(file.uploaded_by || 'Unknown')}</p>
                                        <p class="n88-file-date">${file.uploaded_at ? new Date(file.uploaded_at).toLocaleDateString() : 'N/A'}</p>
                                    </div>
                                    <a href="${escapeHtml(file.url || '#')}" target="_blank" class="btn btn-sm btn-primary">Download</a>
                                </div>
                            `;
                        });
                        html += '</div>';
                    }
                    
                    html += '</div>';
                    container.innerHTML = html;
                }
            })
            .catch(error => console.error('Error loading files:', error));
        },

        // Render quote panel
        renderQuotePanel: function() {
            const container = document.getElementById('quote-content');
            if (!container) return;
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_quote',
                    project_id: this.currentProjectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const quote = data.data;
                    let html = '<div class="n88-quote-panel">';
                    
                    if (!quote) {
                        html += `
                            <div class="n88-quote-empty">
                                <p>No quote has been provided for this project yet.</p>
                                <p class="n88-quote-status">Status: <strong>Awaiting Quote</strong></p>
                            </div>
                        `;
                    } else {
                        const statusClass = `n88-quote-status-${quote.quote_status || 'pending'}`;
                        const statusBadge = quote.quote_status === 'sent' 
                            ? '<span class="n88-badge n88-badge-success">Sent</span>'
                            : '<span class="n88-badge n88-badge-warning">Pending</span>';
                        
                        html += `
                            <div class="n88-quote-detail">
                                <div class="n88-quote-header">
                                    <h3>Project Quote</h3>
                                    ${statusBadge}
                                </div>
                                
                                <div class="n88-quote-info">
                                    <div class="n88-quote-field">
                                        <label>Status</label>
                                        <p><strong>${escapeHtml(quote.quote_status || 'Pending')}</strong></p>
                                    </div>
                                    
                                    <div class="n88-quote-field">
                                        <label>Created</label>
                                        <p>${quote.created_at ? new Date(quote.created_at).toLocaleString() : 'N/A'}</p>
                                    </div>
                                    
                                    ${quote.sent_at ? `
                                    <div class="n88-quote-field">
                                        <label>Sent</label>
                                        <p>${new Date(quote.sent_at).toLocaleString()}</p>
                                    </div>
                                    ` : ''}
                                    
                                    ${quote.admin_notes ? `
                                    <div class="n88-quote-field">
                                        <label>Admin Notes</label>
                                        <p>${escapeHtml(quote.admin_notes)}</p>
                                    </div>
                                    ` : ''}
                                </div>
                                
                                ${quote.quote_file_url ? `
                                <div class="n88-quote-download">
                                    <a href="${escapeHtml(quote.quote_file_url)}" target="_blank" class="btn btn-primary">
                                        📄 Download Quote
                                    </a>
                                </div>
                                ` : ''}
                            </div>
                        `;
                    }
                    
                    html += '</div>';
                    container.innerHTML = html;
                }
            })
            .catch(error => console.error('Error loading quote:', error));
        },

        // Helper: Get file icon based on extension
        getFileIcon: function(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const icons = {
                'pdf': '📄',
                'jpg': '🖼️',
                'jpeg': '🖼️',
                'png': '🖼️',
                'gif': '🖼️',
                'dwg': '📐',
                'doc': '📋',
                'docx': '📋',
                'zip': '🗜️'
            };
            return icons[ext] || '📎';
        },

        // Close modal
        closeModal: function() {
            const modal = document.getElementById('n88-project-modal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    };

    // Comments Manager
    const N88Comments = {
        addComment: function(projectId, itemId, button) {
            const textarea = button.previousElementSibling;
            const comment = textarea.value.trim();

            if (!comment) return;

            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_add_comment',
                    project_id: projectId,
                    item_id: itemId,
                    comment_text: comment,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    textarea.value = '';
                    this.loadItemComments(projectId, itemId);
                }
            })
            .catch(error => console.error('Error adding comment:', error));
        },

        loadItemComments: function(projectId, itemId) {
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_comments',
                    project_id: projectId,
                    item_id: itemId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.renderComments(data.data, `comments-${itemId}`);
                }
            })
            .catch(error => console.error('Error loading comments:', error));
        },

        loadProjectComments: function(projectId) {
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_comments',
                    project_id: projectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('comments-content');
                    if (container) {
                        let html = '<div class="n88-comments-section">';
                        data.data.forEach(comment => {
                            html += this.renderCommentHTML(comment);
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    }
                }
            })
            .catch(error => console.error('Error loading project comments:', error));
        },

        renderComments: function(comments, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            let html = '';
            comments.forEach(comment => {
                html += this.renderCommentHTML(comment);
            });

            container.innerHTML = html;
        },

        renderCommentHTML: function(comment) {
            return `
                <div class="n88-comment">
                    <div class="n88-comment-header">
                        <strong>${escapeHtml(comment.user_name)}</strong>
                        <span class="n88-comment-time">${comment.created_at}</span>
                    </div>
                    <p>${escapeHtml(comment.comment_text)}</p>
                    ${comment.can_delete ? `<button class="n88-delete-comment" onclick="N88Comments.deleteComment(${comment.id})">Delete</button>` : ''}
                </div>
            `;
        },

        deleteComment: function(commentId) {
            if (!confirm('Delete this comment?')) return;

            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_delete_comment',
                    comment_id: commentId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => console.error('Error deleting comment:', error));
        }
    };

    // Quotes Manager
    const N88Quotes = {
        loadProjectQuote: function(projectId) {
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_quote',
                    project_id: projectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('quote-content');
                if (container) {
                    if (data.success && data.data) {
                        container.innerHTML = this.renderQuoteHTML(data.data);
                    } else {
                        container.innerHTML = '<p>No quote available yet.</p>';
                    }
                }
            })
            .catch(error => console.error('Error loading quote:', error));
        },

        renderQuoteHTML: function(quote) {
            return `
                <div class="n88-quote-panel">
                    <div class="n88-quote-status">Status: <strong>${escapeHtml(quote.quote_status)}</strong></div>
                    <div class="n88-quote-file">
                        <a href="${escapeHtml(quote.quote_file_url)}" download>Download Quote</a>
                    </div>
                    ${quote.admin_notes ? `<div class="n88-quote-notes"><p>${escapeHtml(quote.admin_notes)}</p></div>` : ''}
                    <div class="n88-quote-dates">
                        <p>Created: ${quote.created_at}</p>
                        ${quote.sent_at ? `<p>Sent: ${quote.sent_at}</p>` : ''}
                    </div>
                </div>
            `;
        }
    };

    // File Manager
    const N88Files = {
        handleDrop: function(e, projectId, itemId) {
            e.preventDefault();
            const files = e.dataTransfer.files;
            this.uploadFiles(files, projectId, itemId);
        },

        handleDragOver: function(e) {
            e.preventDefault();
            e.currentTarget.style.background = '#f0f0f0';
        },

        handleFileSelect: function(e, projectId, itemId) {
            const files = e.target.files;
            this.uploadFiles(files, projectId, itemId);
        },

        uploadFiles: function(files, projectId, itemId) {
            // Validate file types
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 
                                 'application/acad', 'application/x-acad', 'image/vnd.dwg', 
                                 'application/dwg', 'application/x-dwg', 'image/x-dwg'];
            
            const validFiles = Array.from(files).filter(file => {
                return allowedTypes.includes(file.type) || 
                       ['.pdf', '.jpg', '.jpeg', '.png', '.gif', '.dwg'].some(ext => 
                           file.name.toLowerCase().endsWith(ext));
            });
            
            if (validFiles.length === 0) {
                alert('Please upload only PDF, JPG, PNG, GIF, or DWG files.');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'n88_upload_item_file');
            formData.append('project_id', projectId);
            formData.append('item_id', itemId);
            formData.append('nonce', n88.nonce);

            for (let file of validFiles) {
                formData.append('files[]', file);
            }

            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.loadItemFiles(projectId, itemId);
                }
            })
            .catch(error => console.error('Error uploading file:', error));
        },

        loadItemFiles: function(projectId, itemId) {
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_item_files',
                    project_id: projectId,
                    item_id: itemId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById(`files-${itemId}`);
                    if (container) {
                        let html = '';
                        data.data.forEach(file => {
                            const fileIcon = this.getFileIcon(file.name);
                            html += `
                                <div class="n88-file-item">
                                    <span class="n88-file-icon">${fileIcon}</span>
                                    <a href="${escapeHtml(file.url)}" target="_blank">${escapeHtml(file.name)}</a>
                                    <button class="n88-delete-file" onclick="N88Files.deleteFile(${file.id}, ${projectId}, '${itemId}')" title="Delete file">×</button>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                    }
                }
            })
            .catch(error => console.error('Error loading files:', error));
        },
        
        getFileIcon: function(fileName) {
            const ext = fileName.split('.').pop().toLowerCase();
            if (ext === 'pdf') return '📄';
            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) return '🖼️';
            if (ext === 'dwg') return '📐';
            return '📎';
        },
        
        deleteFile: function(fileId, projectId, itemId) {
            if (!confirm('Delete this file?')) return;
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_delete_item_file',
                    file_id: fileId,
                    project_id: projectId,
                    item_id: itemId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    N88Files.loadItemFiles(projectId, itemId);
                } else {
                    alert('Failed to delete file');
                }
            })
            .catch(error => {
                console.error('Error deleting file:', error);
                alert('Error deleting file');
            });
        }
    };

    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on document ready
    document.addEventListener('DOMContentLoaded', function() {
        // Add event listeners for modal triggers
        document.querySelectorAll('.n88-project-trigger').forEach(element => {
            element.addEventListener('click', function(e) {
                e.preventDefault();
                const projectId = this.dataset.projectId;
                N88Modal.openProjectModal(projectId);
            });
        });

        // Close modal on overlay click
        const modal = document.getElementById('n88-project-modal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    N88Modal.closeModal();
                }
            });
        }
    });

    // Expose to global scope
    window.N88Modal = N88Modal;
    window.N88Comments = N88Comments;
    window.N88Quotes = N88Quotes;
    window.N88Files = N88Files;
    
    // Project Detail Page Comments Handler
    const N88ProjectDetails = {
        // Initialize: Load project comments on page load
        init: function() {
            // Load project-level comments if container exists
            const projectCommentsContainer = document.querySelector('[id^="comments-list-project-"]');
            if (projectCommentsContainer) {
                const projectId = projectCommentsContainer.id.replace('comments-list-project-', '');
                if (projectId) {
                    this.loadProjectComments(projectId);
                }
            }
        },
        
        toggleCommentRow: function(button) {
            const projectId = button.dataset.projectId;
            const itemId = button.dataset.itemId;
            const rowId = `comment-row-${itemId}`;
            const row = document.getElementById(rowId);
            
            if (!row) return;
            
            if (row.style.display === 'none') {
                row.style.display = 'table-row';
                this.loadItemComments(projectId, itemId);
            } else {
                row.style.display = 'none';
            }
        },
        
        loadItemComments: function(projectId, itemId) {
            const listContainer = document.getElementById(`comments-list-${itemId}`);
            if (!listContainer) return;
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_comments',
                    project_id: projectId,
                    item_id: itemId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.renderCommentsList(data.data, listContainer, projectId, itemId);
                } else {
                    listContainer.innerHTML = '<p class="n88-error">Failed to load comments</p>';
                }
            })
            .catch(error => {
                console.error('Error loading comments:', error);
                listContainer.innerHTML = '<p class="n88-error">Error loading comments</p>';
            });
        },
        
        renderCommentsList: function(comments, container, projectId, itemId, videoId) {
            if (comments.length === 0) {
                container.innerHTML = '<p class="n88-no-comments">No comments yet. Be the first to comment!</p>';
                return;
            }
            
            // Separate parent comments and replies
            const parentComments = comments.filter(c => !c.parent_comment_id);
            const repliesMap = {};
            comments.forEach(c => {
                if (c.parent_comment_id) {
                    if (!repliesMap[c.parent_comment_id]) {
                        repliesMap[c.parent_comment_id] = [];
                    }
                    repliesMap[c.parent_comment_id].push(c);
                }
            });
            
            let html = '';
            
            // Render parent comments with their replies
            parentComments.forEach(comment => {
                html += this.renderCommentItem(comment, projectId, itemId, videoId, repliesMap[comment.id] || []);
            });
            
            container.innerHTML = html;
        },
        
        renderCommentItem: function(comment, projectId, itemId, videoId, replies) {
            const canDelete = comment.can_delete || false;
            const canEdit = comment.can_edit || false;
            const isUrgent = comment.is_urgent || false;
            const isReply = comment.parent_comment_id ? true : false;
            const urgentClass = isUrgent ? 'n88-comment-urgent' : '';
            const replyClass = isReply ? 'n88-comment-reply' : '';
            
            let html = `
                <div class="n88-comment-item ${urgentClass} ${replyClass}" data-comment-id="${comment.id}">
                    <div class="n88-comment-meta">
                        <span class="n88-comment-author"><strong>${escapeHtml(comment.user_name || 'Unknown')}</strong></span>
                        ${isUrgent ? '<span class="n88-comment-urgent-badge" style="background: #ffebee; color: #c62828; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-left: 8px;">🚨 URGENT</span>' : ''}
                        ${isReply ? '<span class="n88-comment-reply-badge" style="color: #666; font-size: 12px; margin-left: 8px;">↳ Reply</span>' : ''}
                        <span class="n88-comment-time">${escapeHtml(comment.created_at_ago || '')}</span>
                        ${comment.was_edited ? `<span class="n88-comment-edited">(edited)</span>` : ''}
                    </div>
                    <div class="n88-comment-text">
                        ${escapeHtml(comment.comment_text)}
                    </div>
                    <div class="n88-comment-actions">
                        <button class="n88-btn-reply" onclick="N88ProjectDetails.showReplyForm(${comment.id}, ${projectId}, '${itemId || ''}', '${videoId || ''}')">Reply</button>
                        ${canEdit ? `<button class="n88-btn-edit" onclick="N88ProjectDetails.editComment(${comment.id}, ${projectId}, '${itemId || ''}', '${videoId || ''}')">Edit</button>` : ''}
                        ${canDelete ? `<button class="n88-btn-delete" onclick="N88ProjectDetails.deleteComment(${comment.id}, ${projectId}, '${itemId || ''}', '${videoId || ''}')">Delete</button>` : ''}
                    </div>
            `;
            
            // Render replies if any
            if (replies && replies.length > 0) {
                html += '<div class="n88-comment-replies" style="margin-left: 30px; margin-top: 15px; padding-left: 20px; border-left: 2px solid #e0e0e0;">';
                replies.forEach(reply => {
                    html += this.renderCommentItem(reply, projectId, itemId, videoId, []);
                });
                html += '</div>';
            }
            
            // Reply form (hidden by default)
            html += `
                    <div class="n88-reply-form" id="reply-form-${comment.id}" style="display: none; margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                        <textarea class="n88-comment-input" placeholder="Write a reply..." style="width: 100%; min-height: 60px; margin-bottom: 8px;"></textarea>
                        <div class="n88-comment-form-options" style="display: flex; align-items: center; gap: 15px; margin-bottom: 8px;">
                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="checkbox" class="n88-comment-urgent">
                                <span style="color: #c62828; font-weight: 600; font-size: 12px;">⚠ Urgent</span>
                            </label>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn-submit-comment" onclick="N88ProjectDetails.submitReply(${comment.id}, ${projectId}, '${itemId || ''}', '${videoId || ''}')">Post Reply</button>
                            <button type="button" onclick="N88ProjectDetails.cancelReply(${comment.id})" style="background: #999;">Cancel</button>
                        </div>
                    </div>
                </div>
            `;
            
            return html;
        },
        
        showReplyForm: function(parentCommentId, projectId, itemId, videoId) {
            const replyForm = document.getElementById('reply-form-' + parentCommentId);
            if (replyForm) {
                // Hide all other reply forms
                document.querySelectorAll('.n88-reply-form').forEach(form => {
                    if (form.id !== 'reply-form-' + parentCommentId) {
                        form.style.display = 'none';
                    }
                });
                replyForm.style.display = 'block';
                replyForm.querySelector('textarea').focus();
            }
        },
        
        cancelReply: function(parentCommentId) {
            const replyForm = document.getElementById('reply-form-' + parentCommentId);
            if (replyForm) {
                replyForm.style.display = 'none';
                replyForm.querySelector('textarea').value = '';
                replyForm.querySelector('.n88-comment-urgent').checked = false;
            }
        },
        
        submitReply: function(parentCommentId, projectId, itemId, videoId) {
            const replyForm = document.getElementById('reply-form-' + parentCommentId);
            if (!replyForm) return;
            
            const textarea = replyForm.querySelector('textarea');
            const urgentCheckbox = replyForm.querySelector('.n88-comment-urgent');
            const commentText = textarea.value.trim();
            const isUrgent = urgentCheckbox ? urgentCheckbox.checked : false;
            
            if (!commentText) {
                alert('Please enter a reply');
                return;
            }
            
            const submitBtn = replyForm.querySelector('.btn-submit-comment');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Posting...';
            
            const params = {
                action: 'n88_add_comment',
                project_id: projectId,
                comment_text: commentText,
                is_urgent: isUrgent ? 1 : 0,
                parent_comment_id: parentCommentId,
                nonce: n88.nonce
            };
            
            if (itemId) {
                params.item_id = itemId;
            }
            if (videoId) {
                params.video_id = videoId;
            }
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams(params)
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Post Reply';
                
                if (data.success) {
                    textarea.value = '';
                    if (urgentCheckbox) {
                        urgentCheckbox.checked = false;
                    }
                    
                    // Reload comments
                    if (itemId) {
                        this.loadItemComments(projectId, itemId);
                    } else if (videoId) {
                        this.loadVideoComments(projectId, videoId);
                    } else {
                        this.loadProjectComments(projectId);
                    }
                } else {
                    alert('Failed to post reply: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error posting reply:', error);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Post Reply';
                alert('Error posting reply');
            });
        },
        
        loadVideoComments: function(projectId, videoId) {
            // Similar to loadItemComments but for videos
            const container = document.getElementById('comments-list-video-' + videoId);
            if (!container) return;
            
            container.innerHTML = '<p class="n88-loading">Loading comments...</p>';
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_comments',
                    project_id: projectId,
                    video_id: videoId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.renderCommentsList(data.data, container, projectId, null, videoId);
                } else {
                    container.innerHTML = '<p class="n88-error">Error loading comments</p>';
                }
            })
            .catch(error => {
                console.error('Error loading comments:', error);
                container.innerHTML = '<p class="n88-error">Error loading comments</p>';
            });
        },
        
        loadProjectComments: function(projectId) {
            // Load project-level comments
            const container = document.getElementById('comments-list-project-' + projectId);
            if (!container) return;
            
            container.innerHTML = '<p class="n88-loading">Loading comments...</p>';
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_get_project_comments',
                    project_id: projectId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.renderCommentsList(data.data, container, projectId, null, null);
                } else {
                    container.innerHTML = '<p class="n88-error">Error loading comments</p>';
                }
            })
            .catch(error => {
                console.error('Error loading comments:', error);
                container.innerHTML = '<p class="n88-error">Error loading comments</p>';
            });
        },
        
        submitComment: function(button) {
            const projectId = button.dataset.projectId;
            const itemId = button.dataset.itemId || null;
            const videoId = button.dataset.videoId || null;
            const parentCommentId = button.dataset.parentCommentId || null;
            
            // Find the comment form container
            const formContainer = button.closest('.n88-comment-form');
            const textarea = formContainer ? formContainer.querySelector('.n88-comment-input') : button.previousElementSibling;
            const urgentCheckbox = formContainer ? formContainer.querySelector('.n88-comment-urgent') : null;
            
            const commentText = textarea ? textarea.value.trim() : '';
            const isUrgent = urgentCheckbox ? urgentCheckbox.checked : false;
            
            if (!commentText) {
                alert('Please enter a comment');
                return;
            }
            
            button.disabled = true;
            button.textContent = 'Posting...';
            
            const params = {
                action: 'n88_add_comment',
                project_id: projectId,
                comment_text: commentText,
                is_urgent: isUrgent ? 1 : 0,
                nonce: n88.nonce
            };
            
            if (itemId) {
                params.item_id = itemId;
            }
            if (videoId) {
                params.video_id = videoId;
            }
            if (parentCommentId) {
                params.parent_comment_id = parentCommentId;
            }
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams(params)
            })
            .then(response => response.json())
            .then(data => {
                button.disabled = false;
                button.textContent = 'Post Comment';
                
                if (data.success) {
                    if (textarea) {
                        textarea.value = '';
                    }
                    if (urgentCheckbox) {
                        urgentCheckbox.checked = false;
                    }
                    
                    // Reload comments based on context
                    if (itemId) {
                        this.loadItemComments(projectId, itemId);
                        this.updateCommentCount(projectId, itemId);
                    } else if (videoId) {
                        this.loadVideoComments(projectId, videoId);
                    } else {
                        this.loadProjectComments(projectId);
                    }
                } else {
                    // Enhanced error display
                    let errorMsg = 'Failed to post comment';
                    if (data.data) {
                        if (typeof data.data === 'string') {
                            errorMsg = data.data;
                        } else if (data.data.message) {
                            errorMsg = data.data.message;
                            if (data.data.details && data.data.details.reason) {
                                console.error('Comment error reason:', data.data.details.reason);
                                if (data.data.details.reason === 'permission_denied') {
                                    errorMsg = 'You do not have permission to comment on this project.';
                                } else if (data.data.details.reason === 'sanitization_failed') {
                                    errorMsg = 'Your comment contains invalid content. Please try again.';
                                }
                            }
                        }
                    }
                    console.error('Comment submission failed:', data);
                    alert(errorMsg);
                }
            })
            .catch(error => {
                console.error('Error posting comment:', error);
                button.disabled = false;
                button.textContent = 'Post Comment';
                alert('Error posting comment');
            });
        },
        
        deleteComment: function(commentId, projectId, itemId, videoId) {
            if (!confirm('Are you sure you want to delete this comment?')) return;
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_delete_comment',
                    comment_id: commentId,
                    nonce: n88.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload comments based on context
                    if (itemId) {
                        this.loadItemComments(projectId, itemId);
                        this.updateCommentCount(projectId, itemId);
                    } else if (videoId) {
                        this.loadVideoComments(projectId, videoId);
                    } else {
                        this.loadProjectComments(projectId);
                    }
                } else {
                    alert('Failed to delete comment');
                }
            })
            .catch(error => {
                console.error('Error deleting comment:', error);
                alert('Error deleting comment');
            });
        },
        
        editComment: function(commentId, projectId, itemId, videoId) {
            alert('Edit functionality coming soon');
        },
        
        updateCommentCount: function(projectId, itemId, videoId) {
            if (!itemId && !videoId) {
                // Project-level comments - reload page to update count
                return;
            }
            
            const params = {
                action: 'n88_get_comments',
                project_id: projectId,
                nonce: n88.nonce
            };
            
            if (itemId) {
                params.item_id = itemId;
            }
            if (videoId) {
                params.video_id = videoId;
            }
            
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams(params)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const count = data.data ? data.data.length : 0;
                    let button;
                    if (itemId) {
                        button = document.querySelector(`[data-project-id="${projectId}"][data-item-id="${itemId}"]`);
                    } else if (videoId) {
                        button = document.querySelector(`[data-project-id="${projectId}"][data-video-id="${videoId}"]`);
                    }
                    if (button) {
                        button.textContent = `💬 ${count}`;
                    }
                }
            })
            .catch(error => console.error('Error updating comment count:', error));
        }
    };
    
    // Expose to global scope IMMEDIATELY after object creation
    // This must happen inside the IIFE but before any async operations
    window.N88ProjectDetails = N88ProjectDetails;
    
    // Also expose as a fallback
    if (typeof window.n88 === 'undefined') {
        window.n88 = {};
    }
    window.n88.ProjectDetails = N88ProjectDetails;
    
    // Debug: Log that object is available
    if (typeof console !== 'undefined' && console.log) {
        console.log('N88ProjectDetails initialized and available');
    }
    
    // Notification Center Handler
    const N88Notifications = {
        initialized: false,
        wrapper: null,
        bellButton: null,
        panel: null,
        list: null,
        emptyState: null,
        loadingState: null,
        countBadge: null,
        markAllBtn: null,
        detailTemplate: '',
        currentNotifications: [],
        pollHandle: null,

        init: function() {
            if (this.initialized || typeof n88 === 'undefined') {
                return;
            }

            this.wrapper = document.querySelector('.n88-notification-center');
            if (!this.wrapper) {
                return;
            }

            this.initialized = true;
            this.detailTemplate = this.wrapper.dataset.detailTemplate || '';
            this.bellButton = this.wrapper.querySelector('.n88-notification-bell');
            this.panel = this.wrapper.querySelector('.n88-notification-panel');
            this.list = this.wrapper.querySelector('.n88-notification-list');
            this.emptyState = this.wrapper.querySelector('.n88-notification-empty');
            this.loadingState = this.wrapper.querySelector('.n88-notification-loading');
            this.countBadge = this.wrapper.querySelector('.n88-notification-count');
            this.markAllBtn = this.wrapper.querySelector('.n88-btn-mark-all');
            this.currentNotifications = [];

            this.bindEvents();
            this.refresh();

            // Poll for unread count every minute
            this.pollHandle = setInterval(() => {
                this.fetchUnreadCount();
            }, 60000);
        },

        bindEvents: function() {
            if (this.bellButton) {
                this.bellButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.togglePanel();
                });
            }

        document.addEventListener('click', (e) => {
                if (!this.wrapper.contains(e.target)) {
                    this.closePanel();
                }
            });

            if (this.markAllBtn) {
                this.markAllBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.markAllRead();
                });
            }

            if (this.list) {
                this.list.addEventListener('click', (e) => {
                    const item = e.target.closest('.n88-notification-item');
                    if (!item) {
                        return;
                    }
                    e.preventDefault();
                    const notificationId = parseInt(item.dataset.notificationId, 10);
                    if (notificationId) {
                        this.handleNotificationClick(notificationId);
                    }
                });
            }
        },

        refresh: function() {
            this.fetchUnreadCount();
        },

        togglePanel: function() {
            if (!this.panel) {
                return;
            }
            const willShow = !this.panel.classList.contains('is-visible');
            this.panel.classList.toggle('is-visible');
            if (this.bellButton) {
                this.bellButton.classList.toggle('is-open', willShow);
            }
            if (willShow) {
                this.fetchNotifications();
            }
        },

        closePanel: function() {
            if (this.panel && this.panel.classList.contains('is-visible')) {
                this.panel.classList.remove('is-visible');
            }
            if (this.bellButton) {
                this.bellButton.classList.remove('is-open');
            }
        },

        fetchNotifications: function() {
            if (!this.loadingState || !this.list) {
                return;
            }

            this.loadingState.style.display = 'block';
            if (this.emptyState) {
                this.emptyState.style.display = 'none';
            }
            this.list.innerHTML = '';

            const params = new URLSearchParams({
                action: 'n88_get_notifications',
                nonce: n88.nonce,
                limit: 25
            });

            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params
            })
            .then(response => response.json())
            .then(data => {
                this.loadingState.style.display = 'none';
                if (data.success && data.data && Array.isArray(data.data.notifications)) {
                    this.currentNotifications = data.data.notifications;
                    this.renderNotifications(data.data.notifications);
                } else {
                    this.showEmpty('No notifications yet.');
                }
            })
            .catch(error => {
                console.error('Notification fetch error:', error);
                this.loadingState.style.display = 'none';
                this.showEmpty('Unable to load notifications.');
            });
        },

        fetchUnreadCount: function() {
            if (!this.countBadge) {
                return;
            }

            const params = new URLSearchParams({
                action: 'n88_get_unread_count',
                nonce: n88.nonce
            });

            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    this.updateCountBadge(data.data.unread_count || 0);
                }
            })
            .catch(error => {
                console.error('Unread count error:', error);
            });
        },

        markAsRead: function(notificationId) {
            const params = new URLSearchParams({
                action: 'n88_mark_notification_read',
                nonce: n88.nonce,
                notification_id: notificationId
            });

            return fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.fetchUnreadCount();
                }
                return data;
            })
            .catch(error => {
                console.error('Mark notification error:', error);
            });
        },

        markAllRead: function() {
            const params = new URLSearchParams({
                action: 'n88_mark_all_notifications_read',
                nonce: n88.nonce
            });

            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.fetchUnreadCount();
                    this.fetchNotifications();
                }
            })
            .catch(error => {
                console.error('Mark all notifications error:', error);
            });
        },

        renderNotifications: function(notifications) {
            if (!this.list) {
                return;
            }

            this.list.innerHTML = '';

            if (!notifications.length) {
                this.showEmpty('No notifications yet.');
                return;
            }

            this.emptyState.style.display = 'none';

            notifications.forEach(notification => {
                const button = document.createElement('button');
                button.className = 'n88-notification-item' + (notification.is_read ? '' : ' unread');
                button.dataset.notificationId = notification.id;
                button.innerHTML = `
                    <strong>${this.escapeHtml(notification.message)}</strong>
                    <span class="n88-notification-time">${this.formatRelativeTime(notification.created_at)}</span>
                `;
                this.list.appendChild(button);
            });

            if (this.emptyState) {
                this.emptyState.style.display = 'none';
            }
        },

        handleNotificationClick: function(notificationId) {
            const notification = this.currentNotifications.find(item => item.id === notificationId);
            if (!notification) {
                return;
            }

            this.markAsRead(notificationId);

            if (notification.project_id) {
                let targetUrl = this.detailTemplate || '';
                if (targetUrl.includes('__PROJECT__')) {
                    targetUrl = targetUrl.replace('__PROJECT__', notification.project_id);
                } else if (targetUrl) {
                    const separator = targetUrl.includes('?') ? '&' : '?';
                    targetUrl = `${targetUrl}${separator}project_id=${notification.project_id}`;
                } else {
                    targetUrl = `${window.location.origin}/project-detail/?project_id=${notification.project_id}`;
                }

                window.location.href = targetUrl;
            }
        },

        showEmpty: function(message) {
            if (this.emptyState) {
                this.emptyState.textContent = message;
                this.emptyState.style.display = 'block';
            }
            if (this.list) {
                this.list.innerHTML = '';
            }
        },

        updateCountBadge: function(count) {
            if (!this.countBadge) {
                return;
            }
            this.countBadge.textContent = count;
            if (count > 0) {
                this.countBadge.classList.remove('is-zero');
                if (this.bellButton) {
                    this.bellButton.classList.add('has-unread');
                }
            } else {
                this.countBadge.classList.add('is-zero');
                if (this.bellButton) {
                    this.bellButton.classList.remove('has-unread');
                }
            }
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        },

        formatRelativeTime: function(dateString) {
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                const now = new Date();
                const diffMs = now - date;
                const diffMinutes = Math.floor(diffMs / 60000);

                if (diffMinutes < 1) {
                    return 'Just now';
                }
                if (diffMinutes < 60) {
                    return `${diffMinutes}m ago`;
                }
                const diffHours = Math.floor(diffMinutes / 60);
                if (diffHours < 24) {
                    return `${diffHours}h ago`;
                }
                const diffDays = Math.floor(diffHours / 24);
                if (diffDays < 7) {
                    return `${diffDays}d ago`;
                }
                return date.toLocaleDateString();
            } catch (e) {
                return dateString;
            }
        }
    };
    
    // Add event delegation for comment buttons (works even if script loads late)
    function attachCommentEventListeners() {
        // Use event delegation for all comment buttons
        const handleClick = (e) => {
            const targetButton = e.target.closest('.btn-comment-toggle, .btn-submit-comment');
            if (!targetButton) {
                return;
            }

            if (targetButton.disabled) {
                return;
            }

            if (targetButton.classList.contains('btn-comment-toggle')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (targetButton.dataset.itemId && typeof N88ProjectDetails.toggleCommentRow === 'function') {
                    N88ProjectDetails.toggleCommentRow(targetButton);
                } else if (targetButton.dataset.videoId && typeof N88ProjectDetails.toggleVideoCommentSection === 'function') {
                    N88ProjectDetails.toggleVideoCommentSection(targetButton);
                } else {
                    console.error('N88ProjectDetails comment toggle handler missing');
                }
                return;
            }

            if (targetButton.classList.contains('btn-submit-comment')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                targetButton.disabled = true;
                setTimeout(() => {
                    targetButton.disabled = false;
                }, 1200);

                if (typeof N88ProjectDetails.submitComment === 'function') {
                    N88ProjectDetails.submitComment(targetButton);
                } else {
                    console.error('N88ProjectDetails.submitComment not available');
                }
            }
        };

        document.addEventListener('click', handleClick, { capture: true });
    }
    
    // Initialize project details comments on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            attachCommentEventListeners();
            if (typeof N88ProjectDetails !== 'undefined' && N88ProjectDetails.init) {
                N88ProjectDetails.init();
            }
            N88Notifications.init();
        });
    } else {
        attachCommentEventListeners();
        if (typeof N88ProjectDetails !== 'undefined' && N88ProjectDetails.init) {
            N88ProjectDetails.init();
        }
        N88Notifications.init();
    }

    // Phase 2B: PDF Extraction Handler
    // NOTE: PDF Extraction is now defined in assets/n88-rfq-pdf-extraction.js
    // This file should use window.N88StudioOS.PDFExtraction if needed, but PDF extraction
    // functionality is fully handled by the dedicated script file.
    // Follows N88 Studio OS development standards: window.N88StudioOS namespace.
    window.N88Notifications = N88Notifications;

    // Item Edit Handler (Project Detail View - for both Admin and Users)
    // Use event delegation on document to work with dynamically loaded content
    function attachItemEditListeners() {
        console.log('N88 RFQ: attachItemEditListeners called');
        
        const handleItemEditClick = (e) => {
            // Handle edit button clicks
            const editBtn = e.target.closest('.n88-edit-item-btn');
            if (editBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();

                console.log('N88 RFQ: Edit button clicked', editBtn);

                const itemIndex = editBtn.getAttribute('data-item-index');
                const row = editBtn.closest('.n88-item-row');

                if (!row) {
                    console.error('N88 RFQ: Could not find item row');
                    return;
                }

                const isEditMode = row.getAttribute('data-edit-mode') === 'true';
                console.log('N88 RFQ: Edit mode is', isEditMode);

                if (isEditMode) {
                    // Cancel edit mode
                    row.setAttribute('data-edit-mode', 'false');
                    editBtn.textContent = 'Edit';
                    editBtn.style.display = '';
                    
                    // Hide editable fields, show display
                    row.querySelectorAll('.n88-editable-field, .n88-editable-field-group').forEach(function(field) {
                        field.style.display = 'none';
                    });
                    row.querySelectorAll('.n88-item-display').forEach(function(display) {
                        display.style.display = '';
                    });
                    const editActions = row.querySelector('.n88-item-edit-actions');
                    if (editActions) editActions.style.display = 'none';
                } else {
                    // Enter edit mode
                    row.setAttribute('data-edit-mode', 'true');
                    editBtn.style.display = 'none';
                    
                    // Show editable fields, hide display
                    row.querySelectorAll('.n88-item-display').forEach(function(display) {
                        display.style.display = 'none';
                    });
                    row.querySelectorAll('.n88-editable-field, .n88-editable-field-group').forEach(function(field) {
                        field.style.display = '';
                    });
                    const editActions = row.querySelector('.n88-item-edit-actions');
                    if (editActions) editActions.style.display = '';
                }
                return;
            }

            // Handle cancel button
            const cancelBtn = e.target.closest('.n88-cancel-item-btn');
            if (cancelBtn) {
                e.preventDefault();
                e.stopPropagation();

                const btn = e.target;
                const itemIndex = btn.getAttribute('data-item-index');
                const row = btn.closest('.n88-item-row');
                const editBtn = row.querySelector('.n88-edit-item-btn');

                if (!row) return;

                // Reset to original values (reload from display)
                row.querySelectorAll('.n88-editable-field').forEach(function(input) {
                    const fieldName = input.getAttribute('data-field');
                    const display = row.querySelector('.n88-item-display.n88-item-' + fieldName);
                    if (display) {
                        if (input.tagName === 'TEXTAREA') {
                            input.value = display.textContent.trim();
                        } else {
                            input.value = display.textContent.trim();
                        }
                    }
                });

                // Exit edit mode
                row.setAttribute('data-edit-mode', 'false');
                if (editBtn) {
                    editBtn.textContent = 'Edit';
                    editBtn.style.display = '';
                }
                
            row.querySelectorAll('.n88-editable-field, .n88-editable-field-group').forEach(function(field) {
                field.style.display = 'none';
            });
            row.querySelectorAll('.n88-item-display').forEach(function(display) {
                display.style.display = '';
            });
                const editActionsCancel = row.querySelector('.n88-item-edit-actions');
                if (editActionsCancel) editActionsCancel.style.display = 'none';
                return;
            }

            // Handle save item button clicks
            const saveBtn = e.target.closest('.n88-save-item-btn');
            if (saveBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();

                const btn = saveBtn;

            const projectId = btn.getAttribute('data-project-id');
            const itemIndex = btn.getAttribute('data-item-index');
            const row = btn.closest('.n88-item-row');

            if (!row || !projectId || itemIndex === null) {
                alert('Error: Missing project or item data');
                return;
            }

            // Collect field values
            const fields = {
                primary_material: '',
                length: '',
                depth: '',
                height: '',
                quantity: '',
                construction_notes: '',
                finishes: '',
                notes: '',
            };

            // Get all editable fields in this row
            row.querySelectorAll('.n88-editable-field').forEach(function(input) {
                const fieldName = input.getAttribute('data-field');
                if (fieldName && fields.hasOwnProperty(fieldName)) {
                    if (input.tagName === 'TEXTAREA') {
                        fields[fieldName] = input.value;
                    } else {
                        fields[fieldName] = input.value;
                    }
                }
            });

            // Disable button and show loading
            btn.disabled = true;
            btn.textContent = 'Saving...';

            // Send AJAX request
            fetch(n88.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'n88_save_item_edit',
                    project_id: projectId,
                    item_index: itemIndex,
                    nonce: n88.nonce,
                    primary_material: fields.primary_material || '',
                    length: fields.length || '',
                    depth: fields.depth || '',
                    height: fields.height || '',
                    quantity: fields.quantity || '',
                    construction_notes: fields.construction_notes || '',
                    finishes: fields.finishes || '',
                    notes: fields.notes || ''
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update display values
                    if (fields.primary_material) {
                        const display = row.querySelector('.n88-item-display.n88-item-primary_material');
                        if (display) display.textContent = fields.primary_material;
                    }
                    if (fields.length || fields.depth || fields.height) {
                        const display = row.querySelector('.n88-item-display.n88-item-dimensions');
                        if (display) display.textContent = (fields.length || '') + '×' + (fields.depth || '') + '×' + (fields.height || '');
                    }
                    if (fields.quantity) {
                        const display = row.querySelector('.n88-item-display.n88-item-quantity');
                        if (display) display.textContent = fields.quantity;
                    }
                    if (fields.construction_notes !== undefined) {
                        const display = row.querySelector('.n88-item-display.n88-item-construction_notes');
                        if (display) display.innerHTML = fields.construction_notes.replace(/\n/g, '<br>');
                    }
                    if (fields.finishes) {
                        const display = row.querySelector('.n88-item-display.n88-item-finishes');
                        if (display) display.textContent = fields.finishes;
                    }
                    if (fields.notes !== undefined) {
                        const display = row.querySelector('.n88-item-display.n88-item-notes');
                        if (display) display.textContent = fields.notes;
                    }

                    // Exit edit mode
                    row.setAttribute('data-edit-mode', 'false');
                    const editBtn = row.querySelector('.n88-edit-item-btn');
                    if (editBtn) {
                        editBtn.textContent = 'Edit';
                        editBtn.style.display = '';
                    }
                    
                    row.querySelectorAll('.n88-editable-field, .n88-editable-field-group').forEach(function(field) {
                        field.style.display = 'none';
                    });
                    row.querySelectorAll('.n88-item-display').forEach(function(display) {
                        display.style.display = '';
                    });
                    const editActionsSave = row.querySelector('.n88-item-edit-actions');
                    if (editActionsSave) editActionsSave.style.display = 'none';

                    // Show success message
                    const successMsg = document.createElement('div');
                    successMsg.className = 'n88-item-save-success';
                    successMsg.textContent = 'Item updated successfully!';
                    successMsg.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #4caf50; color: #fff; padding: 12px 20px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10000; font-size: 14px;';
                    document.body.appendChild(successMsg);
                    setTimeout(function() {
                        successMsg.remove();
                    }, 3000);

                    // Reload page after 1 second to refresh badges
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    alert('Error: ' + (data.data || 'Failed to save item'));
                    btn.disabled = false;
                    btn.textContent = 'Save';
                }
            })
            .catch(error => {
                console.error('Error saving item:', error);
                alert('Error: Failed to save item. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Save';
            });
            }
        };

        document.addEventListener('click', handleItemEditClick, { capture: true });
        console.log('N88 RFQ: Item edit event listener attached to document');
        
        // Test: Check if buttons exist
        setTimeout(function() {
            const testButtons = document.querySelectorAll('.n88-edit-item-btn');
            console.log('N88 RFQ: Found', testButtons.length, 'edit buttons on page');
        }, 1000);
    }

    // Initialize item edit listeners - use jQuery for better compatibility
    console.log('N88 RFQ: Setting up item edit listeners');
    
    // Use jQuery if available (it's a dependency)
    if (typeof jQuery !== 'undefined') {
        console.log('N88 RFQ: Using jQuery for edit button handlers');
        
        // Use jQuery event delegation (works with dynamically added content)
        jQuery(document).on('click', '.n88-edit-item-btn', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            console.log('N88 RFQ: Edit button clicked via jQuery', this);
            
            const editBtn = jQuery(this);
            const itemIndex = editBtn.attr('data-item-index');
            const row = editBtn.closest('.n88-item-row');
            
            if (row.length === 0) {
                console.error('N88 RFQ: Could not find item row');
                return;
            }
            
            const isEditMode = row.attr('data-edit-mode') === 'true';
            console.log('N88 RFQ: Edit mode is', isEditMode);
            
            if (isEditMode) {
                // Cancel edit mode
                row.attr('data-edit-mode', 'false');
                editBtn.text('Edit').show();
                row.find('.n88-editable-field, .n88-editable-field-group').hide();
                row.find('.n88-item-display').show();
                row.find('.n88-item-edit-actions').hide();
            } else {
                // Enter edit mode
                row.attr('data-edit-mode', 'true');
                editBtn.hide();
                row.find('.n88-item-display').hide();
                row.find('.n88-editable-field, .n88-editable-field-group').show();
                row.find('.n88-item-edit-actions').show();
            }
        });
        
        // Handle cancel button
        jQuery(document).on('click', '.n88-cancel-item-btn', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            const cancelBtn = jQuery(this);
            const row = cancelBtn.closest('.n88-item-row');
            const editBtn = row.find('.n88-edit-item-btn');
            
            // Reset field values from display
            row.find('.n88-editable-field').each(function() {
                const field = jQuery(this);
                const fieldName = field.attr('data-field');
                const display = row.find('.n88-item-display.n88-item-' + fieldName);
                if (display.length) {
                    if (field.is('textarea')) {
                        field.val(display.text().trim());
                    } else {
                        field.val(display.text().trim());
                    }
                }
            });
            
            // Exit edit mode
            row.attr('data-edit-mode', 'false');
            editBtn.text('Edit').show();
            row.find('.n88-editable-field, .n88-editable-field-group').hide();
            row.find('.n88-item-display').show();
            row.find('.n88-item-edit-actions').hide();
        });
        
        // Handle save button
        jQuery(document).on('click', '.n88-save-item-btn', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            const saveBtn = jQuery(this);
            const projectId = saveBtn.attr('data-project-id');
            const itemIndex = saveBtn.attr('data-item-index');
            const row = saveBtn.closest('.n88-item-row');
            
            if (!projectId || !itemIndex) {
                alert('Error: Missing project or item data');
                return;
            }
            
            // Collect field values
            const fields = {
                primary_material: row.find('[data-field="primary_material"]').val() || '',
                length: row.find('[data-field="length"]').val() || '',
                depth: row.find('[data-field="depth"]').val() || '',
                height: row.find('[data-field="height"]').val() || '',
                quantity: row.find('[data-field="quantity"]').val() || '',
                construction_notes: row.find('[data-field="construction_notes"]').val() || '',
                finishes: row.find('[data-field="finishes"]').val() || '',
                notes: row.find('[data-field="notes"]').val() || ''
            };
            
            // Disable button
            saveBtn.prop('disabled', true).text('Saving...');
            
            // Send AJAX request
            jQuery.ajax({
                url: n88.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'n88_save_item_edit',
                    project_id: projectId,
                    item_index: itemIndex,
                    nonce: n88.nonce,
                    primary_material: fields.primary_material,
                    length: fields.length,
                    depth: fields.depth,
                    height: fields.height,
                    quantity: fields.quantity,
                    construction_notes: fields.construction_notes,
                    finishes: fields.finishes,
                    notes: fields.notes
                },
                success: function(response) {
                    if (response.success) {
                        // Update display values
                        if (fields.primary_material) {
                            row.find('.n88-item-display.n88-item-primary_material').text(fields.primary_material);
                        }
                        if (fields.length || fields.depth || fields.height) {
                            row.find('.n88-item-display.n88-item-dimensions').text(
                                (fields.length || '') + '×' + (fields.depth || '') + '×' + (fields.height || '')
                            );
                        }
                        if (fields.quantity) {
                            row.find('.n88-item-display.n88-item-quantity').text(fields.quantity);
                        }
                        if (fields.construction_notes !== undefined) {
                            row.find('.n88-item-display.n88-item-construction_notes').html(
                                fields.construction_notes.replace(/\n/g, '<br>')
                            );
                        }
                        if (fields.finishes) {
                            row.find('.n88-item-display.n88-item-finishes').text(fields.finishes);
                        }
                        if (fields.notes !== undefined) {
                            row.find('.n88-item-display.n88-item-notes').text(fields.notes);
                        }
                        
                        // Exit edit mode
                        row.attr('data-edit-mode', 'false');
                        row.find('.n88-edit-item-btn').text('Edit').show();
                        row.find('.n88-editable-field, .n88-editable-field-group').hide();
                        row.find('.n88-item-display').show();
                        row.find('.n88-item-edit-actions').hide();
                        
                        // Show success message
                        jQuery('<div>')
                            .addClass('n88-item-save-success')
                            .text('Item updated successfully!')
                            .css({
                                position: 'fixed',
                                top: '20px',
                                right: '20px',
                                background: '#4caf50',
                                color: '#fff',
                                padding: '12px 20px',
                                borderRadius: '4px',
                                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                                zIndex: 10000,
                                fontSize: '14px'
                            })
                            .appendTo('body')
                            .delay(3000)
                            .fadeOut(function() {
                                jQuery(this).remove();
                            });
                        
                        // Reload page after 1 second
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        alert('Error: ' + (response.data || 'Failed to save item'));
                        saveBtn.prop('disabled', false).text('Save');
                    }
                },
                error: function() {
                    alert('Error: Failed to save item. Please try again.');
                    saveBtn.prop('disabled', false).text('Save');
                }
            });
        });
    } else {
        // Fallback to vanilla JS
        console.log('N88 RFQ: jQuery not available, using vanilla JS');
        attachItemEditListeners();
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                attachItemEditListeners();
            });
        }
    }
    
    // Also try after a delay to catch dynamically loaded content
    setTimeout(function() {
        console.log('N88 RFQ: Delayed attachment of item edit listeners');
        attachItemEditListeners();
    }, 2000);
    
    // Use MutationObserver to watch for new buttons being added
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            const hasEditButtons = document.querySelectorAll('.n88-edit-item-btn').length > 0;
            if (hasEditButtons) {
                console.log('N88 RFQ: MutationObserver detected edit buttons, attaching listeners');
                attachItemEditListeners();
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

})();

// Final safety check: Log if N88ProjectDetails is available
if (typeof window !== 'undefined') {
    if (typeof window.N88ProjectDetails !== 'undefined') {
        console.log('✓ N88ProjectDetails is available globally');
    } else {
        console.error('✗ N88ProjectDetails is NOT available globally - script may have errors');
    }
}
