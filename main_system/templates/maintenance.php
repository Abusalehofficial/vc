<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Website Under Maintenance</title>
  <link href="https://fonts.googleapis.com/css?family=Comfortaa|Lato|Raleway:700&display=swap" rel="stylesheet">
  <style>
    html, body {
      height: 100%;
      margin: 0;
    }

    body {
      background: radial-gradient(circle at top left, #0d0d0d, #1a1a1a, #111);
      display: flex;
      justify-content: center;
      align-items: center;
      font-family: 'Lato', sans-serif;
      color: #e0e0e0;
      text-align: center;
      overflow: hidden;
      position: relative;
    }

    .container {
      background: rgba(30, 30, 30, 0.95);
      padding: 50px;
      border-radius: 20px;
      box-shadow: 0 0 30px rgba(0, 0, 0, 0.8);
      max-width: 400px;
      width: 90%;
      z-index: 2;
    }

    h2 {
      font-family: 'Comfortaa', cursive;
      font-size: 40px;
      margin-bottom: 20px;
      color: #ffcc00;
      text-shadow: 2px 2px 8px #000;
    }

    p {
      font-size: 22px;
      margin-bottom: 30px;
      color: #cccccc;
      text-shadow: 1px 1px 6px #000;
    }

    .progress-bar {
      height: 10px;
      width: 100%;
      background: #2a2a2a;
      border-radius: 15px;
      overflow: hidden;
      box-shadow: 0 0 10px #000;
    }

    .progress-bar span {
      display: block;
      height: 100%;
      width: 0;
      background: linear-gradient(90deg, #ffcc00, #ff6600);
      border-radius: 15px;
      animation: progress 3s infinite;
    }

    @keyframes progress {
      0% {
        width: 0;
      }
      50% {
        width: 100%;
      }
      100% {
        width: 0;
      }
    }

    .particles {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      overflow: hidden;
      z-index: 1;
      pointer-events: none;
    }

    .particle {
      position: absolute;
      bottom: -10px;
      width: 6px;
      height: 6px;
      background: radial-gradient(circle, #ff9900, transparent);
      border-radius: 50%;
      animation: rise linear infinite;
      opacity: 0.7;
    }

    @keyframes rise {
      0% {
        transform: translateY(0) scale(0.5);
        opacity: 0.8;
      }
      100% {
        transform: translateY(-120vh) scale(1.1);
        opacity: 0;
      }
    }

    @media (max-width: 600px) {
      .container {
        padding: 30px;
      }

      h2 {
        font-size: 30px;
      }

      p {
        font-size: 18px;
      }
    }
  </style>
</head>
<body>

  <!-- Particle Background -->
  <div class="particles">
    <script>
      for (let i = 0; i < 30; i++) {
        const p = document.createElement('div');
        p.className = 'particle';
        p.style.left = `${Math.random() * 100}%`;
        p.style.animationDuration = `${3 + Math.random() * 5}s`;
        p.style.animationDelay = `${Math.random() * 5}s`;
        document.currentScript.parentElement.appendChild(p);
      }
    </script>
  </div>

  <!-- Maintenance Box -->
  <div class="container">
    <h2>Maintenance Mode</h2>
    <p>We'll be back shortly!</p>
    <div class="progress-bar">
      <span></span>
    </div>
  </div>

</body>
</html>
