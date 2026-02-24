<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 页面底部组件
 * 功能：包含页面底部版权信息和通用脚本
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */
?>
        </main>
    </div>

    <!-- 页面底部 -->
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">© <?php echo date('Y'); ?> 星巴克门店智能效期管理系统 V3.0.0</span>
                <span class="text-muted">
                    <i class="fas fa-code-branch"></i> 
                    <?php
                    // 尝试获取当前 Git 分支
                    $branch = 'master';
                    if (file_exists('.git/HEAD')) {
                        $head = trim(file_get_contents('.git/HEAD'));
                        if (strpos($head, 'ref:') === 0) {
                            $branch = basename($head);
                        }
                    }
                    echo $branch;
                    ?>
                </span>
            </div>
        </div>
    </footer>

    <!-- 侧边栏样式 - 移动端 -->
    <style>
        .sidebar-overlay {
            position: fixed;
            top: 60px;
            left: 0;
            width: 100%;
            height: calc(100vh - 60px);
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 99;
            display: none;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* 侧边栏切换动画 */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
    
    <!-- 通用 JavaScript -->
    <script>
        // 侧边栏切换功能
        $(document).ready(function() {
            // 侧边栏切换按钮
            $('#sidebarToggle').on('click', function() {
                $('#sidebar').toggleClass('active');
                $('#sidebarOverlay').toggleClass('active');
                $('body').toggleClass('sidebar-open');
                
                // 阻止页面滚动
                if ($('#sidebar').hasClass('active')) {
                    $('body').css('overflow', 'hidden');
                } else {
                    $('body').css('overflow', 'auto');
                }
            });
            
            // 侧边栏覆盖层点击
            $('#sidebarOverlay').on('click', function() {
                $('#sidebar').removeClass('active');
                $('#sidebarOverlay').removeClass('active');
                $('body').removeClass('sidebar-open');
                $('body').css('overflow', 'auto');
            });
            
            // 侧边栏导航点击（移动端）
            $('#sidebar .list-group-item').on('click', function() {
                if ($(window).width() < 768) {
                    $('#sidebar').removeClass('active');
                    $('#sidebarOverlay').removeClass('active');
                    $('body').removeClass('sidebar-open');
                    $('body').css('overflow', 'auto');
                }
            });
            
            // 响应式调整
            $(window).on('resize', function() {
                if ($(window).width() >= 768) {
                    $('#sidebar').removeClass('active');
                    $('#sidebarOverlay').removeClass('active');
                    $('body').removeClass('sidebar-open');
                    $('body').css('overflow', 'auto');
                }
            });
            
            // 初始化 DataTables 响应式设置
            if ($.fn.DataTable) {
                $('.data-table').DataTable({
                    "language": {
                        "url": "https://cdn.datatables.net/plug-ins/1.13.6/i18n/zh-CN.json"
                    },
                    "responsive": true,
                    "paging": true,
                    "ordering": true,
                    "info": true,
                    "searching": true,
                    "scrollX": true
                });
            }

            // 平滑滚动
            $('a[href^="#"]').on('click', function(e) {
                e.preventDefault();
                var target = $(this.getAttribute('href'));
                if (target.length) {
                    $('html, body').stop().animate({
                        scrollTop: target.offset().top - 70
                    }, 800);
                }
            });

            // 表单验证
            $('form').on('submit', function() {
                var isValid = true;
                $(this).find('input[required], select[required], textarea[required]').each(function() {
                    if ($(this).val().trim() === '') {
                        isValid = false;
                        $(this).addClass('is-invalid');
                    } else {
                        $(this).removeClass('is-invalid');
                    }
                });
                return isValid;
            });

            // 输入框聚焦时移除无效状态
            $('input, select, textarea').on('focus', function() {
                $(this).removeClass('is-invalid');
            });

            // 加载指示器
            var loadingIndicator = {
                show: function(message) {
                    var html = `
                        <div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-body text-center">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">加载中...</span>
                                        </div>
                                        <p class="mt-3">${message || '处理中，请稍候...'}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    $('body').append(html);
                    $('#loadingModal').modal('show');
                },
                hide: function() {
                    $('#loadingModal').modal('hide');
                    $('#loadingModal').remove();
                }
            };

            // 全局加载指示器
            window.showLoading = loadingIndicator.show;
            window.hideLoading = loadingIndicator.hide;

            // AJAX 请求拦截器
            $(document).ajaxSend(function(event, xhr, options) {
                if (!options.url.includes('getCurrentUser') && !options.url.includes('ping')) {
                    loadingIndicator.show();
                }
            });

            $(document).ajaxComplete(function(event, xhr, options) {
                if (!options.url.includes('getCurrentUser') && !options.url.includes('ping')) {
                    loadingIndicator.hide();
                }
            });

            $(document).ajaxError(function(event, xhr, options, error) {
                loadingIndicator.hide();
                showError('网络请求失败，请检查网络连接');
            });
        });

        // 显示成功消息
        function showSuccess(message) {
            var html = `
                <div class="toast position-fixed top-0 end-0 p-3" style="z-index: 1060;" id="successToast">
                    <div class="toast-header bg-success text-white">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong class="me-auto">成功</strong>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body text-success">
                        ${message}
                    </div>
                </div>
            `;
            $('body').append(html);
            var toast = new bootstrap.Toast($('#successToast'));
            toast.show();
            
            // 3秒后自动删除
            setTimeout(function() {
                $('#successToast').remove();
            }, 3000);
        }

        // 显示错误消息
        function showError(message) {
            var html = `
                <div class="toast position-fixed top-0 end-0 p-3" style="z-index: 1060;" id="errorToast">
                    <div class="toast-header bg-danger text-white">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong class="me-auto">错误</strong>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body text-danger">
                        ${message}
                    </div>
                </div>
            `;
            $('body').append(html);
            var toast = new bootstrap.Toast($('#errorToast'));
            toast.show();
            
            // 5秒后自动删除
            setTimeout(function() {
                $('#errorToast').remove();
            }, 5000);
        }

        // 显示确认对话框
        function confirmAction(message, callback) {
            var html = `
                <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-question-circle text-warning me-2"></i> 确认操作
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                ${message}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" id="confirmBtn">确认</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('body').append(html);
            
            var modal = new bootstrap.Modal($('#confirmModal'));
            modal.show();
            
            $('#confirmBtn').on('click', function() {
                modal.hide();
                if (callback) {
                    callback();
                }
                setTimeout(function() {
                    $('#confirmModal').remove();
                }, 300);
            });
        }

        // 删除操作
        function deleteItem(url, callback) {
            confirmAction('确定要删除此记录吗？此操作无法撤销。', function() {
                $.ajax({
                    url: url,
                    type: 'POST',
                    success: function(response) {
                        if (response.success) {
                            showSuccess('删除成功');
                            if (callback) {
                                callback();
                            }
                        } else {
                            showError(response.error || '删除失败');
                        }
                    },
                    error: function() {
                        showError('删除失败，请检查网络连接');
                    }
                });
            });
        }

        // 导出数据
        function exportData(url, format) {
            $.ajax({
                url: url,
                type: 'GET',
                data: { format: format },
                success: function(response) {
                    if (response.success) {
                        // 创建下载链接
                        var link = document.createElement('a');
                        link.href = response.data;
                        link.download = response.filename;
                        link.click();
                        showSuccess('导出成功');
                    } else {
                        showError(response.error || '导出失败');
                    }
                },
                error: function() {
                    showError('导出失败，请检查网络连接');
                }
            });
        }
    </script>
</body>
</html>
