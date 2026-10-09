<?php
// One question row in paper_builder.php. Expects $q, $qno, $key (section), $subject_id, $set_no, $coding_on.
$pid_for_edit = (int)($q['passage_id'] ?? 0);
$is_code = $q['q_type'] === 'CODE';
?>
<div class="border rounded-lg p-3 flex items-center gap-4 <?= $is_code && !$coding_on ? 'bg-gray-100 opacity-70' : 'bg-white' ?>">
    <div class="w-12 text-center font-bold text-gray-700">Q<?= $qno ?></div>
    <?php if ($is_code): ?>
        <div style="background:#111827;color:#86efac;max-width:220px" class="px-3 py-2 text-sm font-mono whitespace-nowrap overflow-hidden">&lt;/&gt; <?= htmlspecialchars($q['coding']['title'] ?? 'Coding question') ?></div>
    <?php else: ?>
        <img src="<?= htmlspecialchars(img_src($q['q_text_url'])) ?>" class="thumb" loading="lazy">
    <?php endif; ?>
    <div class="flex-grow text-sm">
        <div><span class="font-bold"><?= htmlspecialchars($q['q_type']) ?></span>, <?= (float)$q['marks'] ?> mark<?= (float)$q['marks'] == 1 ? '' : 's' ?>
            <?php if ($is_code): ?>
                <span class="text-gray-600">(<?= htmlspecialchars(implode(', ', array_map(fn($l) => CODING_LANGUAGES[$l], $q['coding']['languages'] ?? []))) ?>)</span>
                <?php if (!$coding_on): ?><span class="ml-2 text-xs font-bold text-red-600">Hidden from candidates (coding is off)</span><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="text-gray-600"><?= $is_code ? 'Test cases' : 'Answer' ?>: <?= answer_text($q) ?></div>
    </div>
    <div class="flex gap-2 text-sm">
        <?php if ($is_code): ?>
        <button onclick="openCoding(<?= $key ?>, <?= (int)$q['id'] ?>)" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</button>
        <?php else: ?>
        <button onclick="openQuestion(<?= $key ?>, <?= $pid_for_edit ?>, <?= (int)$q['id'] ?>)" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</button>
        <?php endif; ?>
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
