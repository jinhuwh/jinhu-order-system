<?php
require_once 'config.php';
initDatabase();

// 匿名使用统计/更新检查（默认关闭，管理员可在系统设置中开启；失败静默不影响登录）
// 不想要统计的用户可直接删除 telemetry.php，此处存在性检查保证删除后系统照常运行
if (file_exists(__DIR__ . '/telemetry.php')) {
    require_once __DIR__ . '/telemetry.php';
    jh_telemetry_ping();
}

// 已登录则跳转首页（移动端来源则跳转回移动端）
if (isset($_SESSION['user_id'])) {
    $redirect = isset($_SESSION['from_mobile']) && $_SESSION['from_mobile'] ? 'mobile/index.php' : 'index.php';
    header('Location: ' . $redirect);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // 暴力破解防护：先检查是否已被锁定
    $lockCheck = isLoginLocked();
    if ($lockCheck !== false) {
        $error = '⚠️ ' . $lockCheck;
    } elseif (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, password, realname, role, status, must_change_password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $remaining = recordLoginAttempt($username);
            $error = '用户名不存在' . ($remaining > 0 ? "（剩余{$remaining}次尝试）" : '');
        } elseif ($user['status'] != 1) {
            $error = '该账号已被禁用';
        } elseif (!password_verify($password, $user['password'])) {
            $remaining = recordLoginAttempt($username);
            $error = '密码错误' . ($remaining > 0 ? "（剩余{$remaining}次尝试）" : '');
        } else {
            // 登录成功：清除该IP的登录尝试记录
            clearLoginAttempts();
            
            // 防止会话固定攻击：登录成功后重新生成会话ID
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['realname'] = $user['realname'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // 记录登录日志
            logOperation('login', ['username' => $username]);
            
            // 检查是否需要强制修改密码
            if ($user['must_change_password'] == 1) {
                $_SESSION['must_change_password'] = true;
                header('Location: change_password.php?force=1');
                exit;
            }
            
            // 如果来自移动端，登录后返回移动端
            $redirect = isset($_SESSION['from_mobile']) && $_SESSION['from_mobile'] ? 'mobile/index.php' : 'index.php';
            header('Location: ' . $redirect);
            exit;
        }
    }
}

$companyName = getSetting('company_name') ?: SITE_TITLE;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #3B82F6;
            --primary-glow: rgba(59, 130, 246, 0.4);
            --accent: #F97316;
            --accent-glow: rgba(249, 115, 22, 0.4);
            --gradient-primary: linear-gradient(135deg, #3B82F6 0%, #8B5CF6 100%);
            --gradient-accent: linear-gradient(135deg, #F97316 0%, #EC4899 100%);
            --gradient-hero: linear-gradient(135deg, #1E1B4B 0%, #312E81 50%, #1E3A8A 100%);
            --gray-50: #F8FAFC;
            --gray-200: #E2E8F0;
            --gray-400: #94A3B8;
            --gray-500: #64748B;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1E293B;
            --danger: #EC4899;
            --warning: #FBBF24;
            --radius-lg: 16px;
            --radius-xl: 24px;
            --radius-2xl: 32px;
            --shadow-float: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0F172A 0%, #1E3A8A 35%, #3B82F6 70%, #8B5CF6 100%);
            position: relative;
            overflow: hidden;
        }

        /* 粒子动画 Canvas */
        #particles-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* 柔光装饰球 */
        .glow-orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            pointer-events: none;
        }
        .glow-orb-1 {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.25) 0%, transparent 70%);
            top: -150px; right: -150px;
            animation: floatOrb 12s ease-in-out infinite;
        }
        .glow-orb-2 {
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.2) 0%, transparent 70%);
            bottom: -100px; left: -100px;
            animation: floatOrb 15s ease-in-out infinite reverse;
        }
        .glow-orb-3 {
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(236, 72, 153, 0.15) 0%, transparent 70%);
            top: 40%; left: 50%;
            animation: floatOrb 18s ease-in-out infinite;
        }
        @keyframes floatOrb {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(40px, -30px) scale(1.1); }
            66% { transform: translate(-30px, 40px) scale(0.95); }
        }
        
        body::before, body::after { display: none; }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: var(--radius-2xl);
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 440px;
            padding: 48px;
            position: relative;
            z-index: 2;
        }
        
        .login-logo {
            width: 72px;
            height: 72px;
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            overflow: hidden;
        }
        
        .login-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .login-title {
            font-size: 24px;
            font-weight: 800;
            text-align: center;
            color: var(--gray-800);
            margin-bottom: 8px;
        }
        
        .login-subtitle {
            font-size: 14px;
            text-align: center;
            color: var(--gray-400);
            margin-bottom: 32px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 10px;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-lg);
            font-size: 15px;
            color: var(--gray-700);
            transition: all 0.25s ease;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-glow);
        }
        
        .form-control::placeholder {
            color: var(--gray-400);
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: var(--radius-lg);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 16px var(--primary-glow);
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px var(--primary-glow);
        }
        
        .error-msg {
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.1) 0%, rgba(244, 114, 182, 0.1) 100%);
            color: var(--danger);
            padding: 14px 18px;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            font-size: 14px;
            text-align: center;
            border: 1px solid rgba(236, 72, 153, 0.2);
        }
        
        .warning-msg {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.1) 0%, rgba(245, 158, 11, 0.1) 100%);
            color: #B45309;
            padding: 14px 18px;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            font-size: 14px;
            text-align: center;
            border: 1px solid rgba(251, 191, 36, 0.2);
        }
        
        .default-account {
            text-align: center;
            margin-top: 24px;
            color: var(--gray-400);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <canvas id="particles-canvas"></canvas>
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>
    <div class="glow-orb glow-orb-3"></div>

    <script>
    // 粒子连线动画（参考导航页效果）
    (function() {
        var canvas = document.getElementById('particles-canvas');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var particles = [];
        var mouseX = -1000, mouseY = -1000;
        var dpr = window.devicePixelRatio || 1;
        var PARTICLE_COUNT = 90;
        var LINK_DIST = 140;
        var MOUSE_LINK_DIST = 180;

        function resize() {
            canvas.width = window.innerWidth * dpr;
            canvas.height = window.innerHeight * dpr;
            canvas.style.width = window.innerWidth + 'px';
            canvas.style.height = window.innerHeight + 'px';
            ctx.scale(dpr, dpr);
        }
        resize();
        window.addEventListener('resize', resize);

        function Particle() {
            this.x = Math.random() * window.innerWidth;
            this.y = Math.random() * window.innerHeight;
            this.vx = (Math.random() - 0.5) * 0.5;
            this.vy = (Math.random() - 0.5) * 0.5;
            this.r = Math.random() * 2.2 + 1.2;
            this.alpha = Math.random() * 0.5 + 0.3;
        }
        Particle.prototype.update = function() {
            this.x += this.vx;
            this.y += this.vy;
            if (this.x < 0 || this.x > window.innerWidth) this.vx *= -1;
            if (this.y < 0 || this.y > window.innerHeight) this.vy *= -1;
        };
        Particle.prototype.draw = function() {
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255, 255, 255, ' + this.alpha + ')';
            ctx.fill();
            // 光晕
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.r * 3, 0, Math.PI * 2);
            var grad = ctx.createRadialGradient(this.x, this.y, 0, this.x, this.y, this.r * 3);
            grad.addColorStop(0, 'rgba(255,255,255,' + (this.alpha * 0.4) + ')');
            grad.addColorStop(1, 'rgba(255,255,255,0)');
            ctx.fillStyle = grad;
            ctx.fill();
        };

        for (var i = 0; i < PARTICLE_COUNT; i++) {
            particles.push(new Particle());
        }

        document.addEventListener('mousemove', function(e) {
            mouseX = e.clientX;
            mouseY = e.clientY;
        });
        document.addEventListener('mouseleave', function() {
            mouseX = -1000;
            mouseY = -1000;
        });

        function animate() {
            ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
            for (var i = 0; i < particles.length; i++) {
                particles[i].update();
                particles[i].draw();
            }
            // 粒子两两连线
            for (var i = 0; i < particles.length; i++) {
                for (var j = i + 1; j < particles.length; j++) {
                    var dx = particles[i].x - particles[j].x;
                    var dy = particles[i].y - particles[j].y;
                    var dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < LINK_DIST) {
                        var opacity = (1 - dist / LINK_DIST) * 0.35;
                        ctx.beginPath();
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.strokeStyle = 'rgba(255, 255, 255, ' + opacity + ')';
                        ctx.lineWidth = 0.6;
                        ctx.stroke();
                    }
                }
            }
            // 鼠标连线
            for (var i = 0; i < particles.length; i++) {
                var dx = particles[i].x - mouseX;
                var dy = particles[i].y - mouseY;
                var dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < MOUSE_LINK_DIST) {
                    var opacity = (1 - dist / MOUSE_LINK_DIST) * 0.7;
                    ctx.beginPath();
                    ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(mouseX, mouseY);
                    ctx.strokeStyle = 'rgba(139, 92, 246, ' + opacity + ')';
                    ctx.lineWidth = 0.8;
                    ctx.stroke();
                }
            }
            requestAnimationFrame(animate);
        }
        animate();
    })();
    </script>
    <div class="login-card">
        <div class="login-logo"><img src="logo.png" alt="<?php echo htmlspecialchars($companyName); ?>"></div>
        <div class="login-title"><?php echo htmlspecialchars($companyName); ?></div>
        <div class="login-subtitle">广告制作订单管理系统</div>
        
        <?php if ($error): ?>
        <div class="error-msg"><?php echo $error; ?></div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'timeout'): ?>
        <div class="warning-msg">⏰ 登录已过期，请重新登录</div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">用户名</label>
                <input type="text" name="username" class="form-control" placeholder="请输入用户名" value="<?php echo htmlspecialchars($username ?? ''); ?>" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">密码</label>
                <input type="password" name="password" class="form-control" placeholder="请输入密码" required>
            </div>
            <button type="submit" class="btn-login">登 录</button>
        </form>
        
        <?php
        // 只在开发环境显示默认账号提示
        $isDev = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '192.168.88.109']) ||
                 (defined('DEBUG_MODE') && DEBUG_MODE);
        if ($isDev):
        ?>
        <div class="default-account">
            默认账号: admin / admin123
        </div>
        <?php endif; ?>
    </div>

    <!-- 动态背景：柔光球 + 粒子动画 -->
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>
    <div class="glow-orb glow-orb-3"></div>
    <div id="particles-js" style="position:fixed; top:0; left:0; width:100%; height:100%; z-index:0; pointer-events:none; filter:drop-shadow(0 0 6px rgba(255,255,255,0.8)) drop-shadow(0 0 12px rgba(180,200,255,0.4));"></div>
    <script src="/js/particles.min.js" defer onerror="console.warn('[login] /js/particles.min.js 加载失败 (404)')"></script>
    <script>
    if (typeof particlesJS === "function") {
    particlesJS("particles-js", {
        particles: {
            number: { value: 30, density: { enable: true, value_area: 800 } },
            color: { value: "#ffffff" },
            shape: { type: "circle", stroke: { width: 0, color: "#000000" } },
            opacity: { value: 0.85, random: false, anim: { enable: true, speed: 1.5, opacity_min: 0.4, sync: false } },
            size: { value: 18, random: true, anim: { enable: false, speed: 40, size_min: 0.1, sync: false } },
            line_linked: { enable: true, distance: 600, color: "#ffffff", opacity: 0.4, width: 1.3 },
            move: { enable: true, speed: 2.5, direction: "none", random: true, straight: false, out_mode: "out", bounce: false }
        },
        interactivity: {
            detect_on: "canvas",
            events: { onhover: { enable: false }, onclick: { enable: false }, resize: true }
        },
        retina_detect: true
    });
    } else {
        console.warn("[login] particlesJS 未加载，跳过粒子背景 (/js/particles.min.js 可能 404)");
    }
    </script>
</body>
</html>