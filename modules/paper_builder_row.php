<?php
// One question row in paper_builder.php. Expects $q, $qno, $key (section), $subject_id, $set_no.
$pid_for_edit = (int)($q['passage_id'] ?? 0);
?>
<div class="border rounded-lg p-3 flex items-center gap-4 bg-white">
    <div class="w-12 text-center font-bold text-gray-700">Q<?= $qno ?></div>
    <img src="<?= htmlspecialchars(img_src($q['q_text_url'])) ?>" class="thumb" loading="lazy">
    <div class="flex-grow text-sm">
        <div><span class="font-bold"><?= htmlspecialchars($q['q_type']) ?></span>, <?= (float)$q['marks'] ?> mark<?= (float)$q['marks'] == 1 ? '' : 's' ?></div>
        <div class="text-gray-600">Answer: <?= answer_text($q) ?></div>
    </div>
    <div class="flex gap-2 text-sm">
        <button onclick="openQuestion(<?= $key ?>, <?= $pid_for_edit ?>, <?= (int)$q['id'] ?>)" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</button>
        <form method="POST" onsubmit="return confirm('Take this question out of the test? It stays in the question bank.')">
            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
            <input type="hidden" name="pb_action" value="remove_question"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <button class="bg-gray-500 text-white px-3 py-1 rounded">Remove</button>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this question permanently?')">
            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
            <input type="hidden" name="pb_action" value="delete_question"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <button class="bg-red-500 text-white px-3 py-1 rounded">Delete</button>
        </form>
    </div>
</div>
