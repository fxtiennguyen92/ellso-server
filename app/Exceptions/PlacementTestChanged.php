<?php

namespace App\Exceptions;

use RuntimeException;

/** Bài nộp có câu không thuộc bộ câu học viên được giao (admin vừa sửa đề / đổi số câu). App tải lại đề. */
class PlacementTestChanged extends RuntimeException {}
