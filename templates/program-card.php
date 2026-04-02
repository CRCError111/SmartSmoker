<div class="col-md-6 col-lg-4 mb-4">
    <div class="card h-100">
        <div class="card-header">
            <h5 class="mb-0">
                <?php if ($program['is_built_in']): ?>
                    <span class="badge bg-info me-2">Встроенная</span>
                <?php endif; ?>
                <?= htmlspecialchars($program['name']) ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($program['description'])): ?>
                <p class="card-text"><?= nl2br(htmlspecialchars($program['description'])) ?></p>
            <?php endif; ?>
            
            <div class="mb-3">
                <?php if (!empty($program['category'])): ?>
                    <span class="badge bg-secondary me-1">
                        🏷️ <?= htmlspecialchars($program['category']) ?>
                    </span>
                <?php endif; ?>
                
                <?php if (isset($program['stages_count']) && $program['stages_count'] > 0): ?>
                    <span class="badge bg-primary me-1">
                        📚 <?= $program['stages_count'] ?> <?= declension($program['stages_count'], ['этап', 'этапа', 'этапов']) ?>
                    </span>
                <?php endif; ?>
                
                <?php if (isset($program['total_duration']) && $program['total_duration'] > 0): ?>
                    <span class="badge bg-info">
                        ⏱️ <?= formatDuration($program['total_duration']) ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($program['device_name'])): ?>
                <p class="text-muted mb-0">
                    🖥️ <?= htmlspecialchars($program['device_name']) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <div class="btn-group w-100" role="group">
                <a href="program-edit.php?id=<?= $program['id'] ?>" class="btn btn-sm btn-outline-primary">
                    ✏️ Редактировать
                </a>
                <button type="button" class="btn btn-sm btn-outline-success"
                        onclick="sendProgramToDevice(<?= $program['id'] ?>, '<?= htmlspecialchars(addslashes($program['name'])) ?>')">
                    📤 На устройство
                </button>
                <?php if (!$program['is_built_in']): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" 
                            onclick="deleteProgram(<?= $program['id'] ?>)">
                        🗑️ Удалить
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
