/**
 * CSRF Token 前端辅助函数
 * 自动获取和发送CSRF token
 */

class CSRFHelper {
    /**
     * 获取CSRF token
     * 
     * @returns string
     */
    static getToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content || 
               document.querySelector('input[name="csrf_token"]')?.value || 
               localStorage.getItem('csrf_token');
    }
    
    /**
     * 设置CSRF token
     * 
     * @param {string} token 
     */
    static setToken(token) {
        localStorage.setItem('csrf_token', token);
        
        // 更新meta标签
        let meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta) {
            meta = document.createElement('meta');
            meta.setAttribute('name', 'csrf-token');
            document.head.appendChild(meta);
        }
        meta.setAttribute('content', token);
        
        // 更新隐藏字段
        let input = document.querySelector('input[name="csrf_token"]');
        if (!input) {
            input = document.createElement('input');
            input.setAttribute('type', 'hidden');
            input.setAttribute('name', 'csrf_token');
            if (document.body) {
                document.body.appendChild(input);
            }
        }
        input.setAttribute('value', token);
    }
    
    /**
     * 发送包含CSRF token的请求
     * 
     * @param {string} url 
     * @param {object} options 
     * @returns Promise<Response>
     */
    static async fetch(url, options = {}) {
        options.headers = options.headers || {};
        options.headers['X-CSRF-Token'] = options.headers['X-CSRF-Token'] || this.getToken();
        
        // 如果是POST请求并且有body，尝试添加到body中
        if (options.method && options.method.toUpperCase() === 'POST' && options.body) {
            try {
                if (typeof options.body === 'string') {
                    const data = JSON.parse(options.body);
                    data.csrf_token = data.csrf_token || this.getToken();
                    options.body = JSON.stringify(data);
                }
            } catch (e) {
                // 如果body不是JSON格式，忽略
            }
        }
        
        return fetch(url, options);
    }
    
    /**
     * 初始化CSRF token
     */
    static async init() {
        try {
            // 首先尝试从页面获取
            const token = this.getToken();
            if (token) {
                return token;
            }
            
            // 从服务器获取
            const response = await fetch('index.php?api=get_csrf_token');
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.token) {
                    this.setToken(data.token);
                    return data.token;
                }
            }
            
            console.warn('CSRF token 初始化失败');
            return null;
        } catch (error) {
            console.error('CSRF token 获取失败:', error);
            return null;
        }
    }
    
    /**
     * 验证CSRF token是否有效
     */
    static isValid() {
        return !!this.getToken();
    }
}

/**
 * 重写原生fetch函数，自动添加CSRF token
 */
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
    // 不需要CSRF验证的接口
    const noCsrfPatterns = [
        'api=login',
        'api=logout',
        'api=get_health',
        'api=get_version'
    ];
    
    // 检查是否需要添加CSRF token
    const needCSRF = !noCsrfPatterns.some(pattern => url.includes(pattern));
    
    if (needCSRF) {
        return CSRFHelper.fetch(url, options);
    }
    
    return originalFetch.call(this, url, options);
};

/**
 * DOM加载完成后初始化CSRF token
 */
document.addEventListener('DOMContentLoaded', () => {
    CSRFHelper.init();
});

// 导出为模块
if (typeof module !== 'undefined') {
    module.exports = CSRFHelper;
}
