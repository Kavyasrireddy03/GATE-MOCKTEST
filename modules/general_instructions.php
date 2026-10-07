<?php
// Shared "General Instructions" text. Included by instructions.php (before the exam)
// and by exam.php (the Instructions popup during the exam). Expects $duration_minutes.
?>
    <h3>General Instructions</h3>
    <p><b>Please read the following carefully.</b></p>
    <ol>
        <li>The duration of the examination is <b><?php echo $duration_minutes; ?></b> minutes. The clock will be set on the server. The countdown timer at the top right-hand corner of your screen displays the time available for you to complete the examination.</li>
        <li>When the timer reaches zero, the examination will end automatically. You will NOT be required to submit your examination.</li>
        <li>The screen is divided in two panels. The panel on the left shows the Questions - one at a time and the narrow panel on the right (below candidate name) has Question Palette and Question numbers.</li>
        <li>The Question Palette shows the status of each question using one of the following symbols:</li>
        
        <table class="palette-table">
            <tr>
                <td><span class="shortcut unvisited up" data-num="1"></span></td>
                <td>You have NOT visited the question yet.</td>
            </tr>
            <tr>
                <td><span class="shortcut unanswered down" data-num="2"></span></td>
                <td>You have NOT answered the question.</td>
            </tr>
            <tr>
                <td><span class="shortcut answered up" data-num="3"></span></td>
                <td>You have answered the question. <b>This will be evaluated.</b></td>
            </tr>
            <tr>
                <td><span class="shortcut review" data-num="4"></span></td>
                <td>You have NOT answered the question but marked it for review.</td>
            </tr>
            <tr>
                <td><span class="shortcut review answered-marked-dot" data-num="5"></span></td>
                <td>You have answered the question and marked it for review. <b>This will also be evaluated.</b></td>
            </tr>
        </table>

        <li>Click on <b>></b> to collapse the Question No. panel and maximize the question window. To undo, click on <b><</b>.</li>
        <li>A <b>scientific calculator</b> is available on the left of your image.</li>
        <li><b>Marking:</b> Each question carries either one mark or two marks, as specified. Questions that are not attempted will result in ZERO marks.</li>
        <li><b>Scribble Pad:</b> You may use the scribble pad provided in the examination hall for rough work. Write your name and registration number on the scribble pad before using it. You can possess ONLY one scribble pad at any point of time. You may ask for a second scribble pad only after returning the first one to the invigilator. Return the scribble pad in your possession to the invigilator at the end of the examination.</li>
    </ol>

    <h4>Navigating through sections</h4>
    <ol start="9">
        <li>This question paper has more than one sections. The details of the Sections are given in the Paper Specific instructions.</li>
        <li>Name of the Sections in the question paper are displayed at the top left of the Questions Panel.</li>
        <li>Questions in a section can be viewed by clicking on the section heading/tab. The tab of the section you are currently viewing is highlighted.</li>
        <li>Clicking the <b>Save & Next</b> button on the last question for a section will take you to the first question of the next section.</li>
        <li>You can switch between sections and questions anytime during the examination by clicking on the appropriate tab.</li>
        <li>You can view the section summary above the question palette.</li>
    </ol>

    <h4>Navigating to a question</h4>
    <ol start="15">
        <li>Click on <b>Save & Next</b> to save your answer for the current question and then go to the next question.</li>
        <li>Click on <b>Mark for Review & Next</b> to save your answer for the current question, mark it for review, and then go to the next question.</li>
        <li>To navigate/go to a question, click on its question number in the Question Palette. <span style="color:red;">This does NOT save your answer to the current question.</span></li>
    </ol>

    <h4>Answering a Question</h4>
    <ol start="18">
        <li>Each <b>Multiple Choice Question (MCQ)</b> and <b>Multiple Select Question (MSQ)</b> has four options.</li>
        <li><b>Multiple Choice Questions (MCQs):</b></li>
        <ul class="alpha-list">
            <li>MCQ has only one correct answer. Wrong answers for MCQs will result in NEGATIVE marks: ⅓ negative mark for a 1-mark question; and ⅔ negative mark for a 2-mark question.</li>
            <li>MCQs have a circular button for each option.</li>
            <li>To select your answer, click on the circular button of one of the option that you want to choose as answer.</li>
            <li>To change your chosen answer, click on the button of another option.</li>
            <li>To deselect your chosen answer, click on the button of the chosen option again or click on the <b>Clear Response</b> button.</li>
        </ul>
        <li style="margin-top: 10px;"><b>Multiple Select Questions (MSQs)</b></li>
        <ul class="alpha-list">
            <li>MSQ has one or more correct options. There is no negative marking for MSQs.</li>
            <li>MSQs have square-shaped checkbox placed before each option.</li>
            <li>Choose your answer by clicking the checkbox(es) placed before each of the selected choice(s).</li>
            <li>To change a particular selected option, deselect the option that you want to change and click on the checkbox of another option.</li>
            <li>To deselect one or more of your selected option(s), either click on the checkbox of the option(s) again or click on the <b>Clear Response</b> button.</li>
        </ul>
        <li style="margin-top: 10px;"><b>Numerical Answer Type (NAT) questions</b></li>
        <ul class="alpha-list">
            <li>To enter a numerical answer, use the virtual numeric keypad that appears below the question.</li>
            <li>To clear your answer, click on the <b>Clear Response</b> button.</li>
        </ul>
    </ol>
