<?php define('SKIP_DB', true); require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Assessment Error</title>
    <script src="<?= CDN_TAILWIND ?>"></script>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
            color: #555555;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        /* Styling the dropdown to match the classic look */
        select {
            border: 1px solid #ccc;
            padding: 2px 4px;
            font-size: 13px;
            border-radius: 2px;
            outline: none;
            color: #333;
        }
    </style>
</head>
<body>



    <div class="w-full flex justify-end items-center px-4 py-3 bg-white">
        <div class="flex items-center gap-2 text-sm text-gray-600">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Change Language</span>
            <select class="ml-1 pr-6 cursor-pointer bg-white">
                <option>English</option>
            </select>
        </div>
    </div>

    <main class="flex-grow flex flex-col items-center pt-8 px-4 w-full max-w-5xl mx-auto text-center">
        
        <div class="mb-6">
            <svg width="240" height="140" viewBox="0 0 240 140" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M120 10 C160 10, 190 30, 190 70 C190 110, 160 130, 120 130 C80 130, 50 110, 50 70 C50 30, 80 10, 120 10 Z" fill="#eff6ff"/>
                <rect x="40" y="50" width="160" height="8" rx="4" fill="#ffffff" opacity="0.6"/>
                <rect x="70" y="80" width="130" height="8" rx="4" fill="#ffffff" opacity="0.6"/>
                <path d="M110 100 L130 100 L140 120 L100 120 Z" fill="#94a3b8"/>
                <rect x="90" y="120" width="60" height="4" rx="2" fill="#64748b"/>
                <rect x="80" y="40" width="80" height="60" rx="2" fill="#3b82f6"/>
                <rect x="85" y="45" width="70" height="45" fill="#ffffff"/>
                <path d="M120 52 L138 82 L102 82 Z" fill="#ef4444"/>
                <rect x="118.5" y="60" width="3" height="12" fill="#ffffff"/>
                <circle cx="120" cy="76" r="1.5" fill="#ffffff"/>
            </svg>
        </div>

        <div class="text-[15px] leading-relaxed text-[#4d4d4d] space-y-5">
            <p>
                <span class="text-red-600 font-semibold">Note : </span>System records every single interruption during the Assessment.
            </p>
            
            <p>
                Interruption is recorded in the system due one of the following possible reasons:<br>
                1) You were trying to minimize OR toggle Assessment Console.<br>
                2) You have pressed special keys from your keyboard which are not allowed during Assessment.<br>
                3) You have tried to move out of Assessment Console which is not allowed.<br>
                4) You have tried to refresh the page.
            </p>
            
            <p class="max-w-4xl">
                This window will close down and you have to re-launch the Assessment only after it is unlocked. Please be advised not to move out of console during the assessment and not to navigate to other applications during the assessment.
            </p>

            <hr class="border-gray-200 w-4/5 mx-auto my-6">

            <h2 class="text-lg font-bold text-gray-800">How to proceed</h2>
            
            <p>
                This window will close down now.<br>
                Please ensure that you do not move out of Assessment window during the assessment. Use only mouse to navigate.
            </p>
        </div>

    </main>

    <footer class="bg-[#5b738b] text-white text-center py-[2px] text-[11px] font-medium tracking-wide">
        Version 17.05.21
    </footer>

</body>
</html>