 /*                           BOOKMANAGMENT
//QUERY
CREATE DATABASE IF NOT EXISTS library_skills
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE library_skills;

CREATE TABLE IF NOT EXISTS `Author` (
    authID INT AUTO_INCREMENT PRIMARY KEY,
    authLName VARCHAR(100) NOT NULL,
    authFName VARCHAR(100) NOT NULL,
    authMName VARCHAR(100) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `Student` (
    studID INT AUTO_INCREMENT PRIMARY KEY,
    studFName VARCHAR(100) NOT NULL,
    studMName VARCHAR(100) NOT NULL DEFAULT '',
    studLName VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `Book` (
    bookID INT AUTO_INCREMENT PRIMARY KEY,
    bookTitle VARCHAR(200) NOT NULL,
    bookCopies INT NOT NULL,
    authID INT NOT NULL,
    FOREIGN KEY (authID) REFERENCES `Author`(authID)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `BorrowedBook` (
    borrowID INT AUTO_INCREMENT PRIMARY KEY,
    studID INT NOT NULL,
    bookID INT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Borrowed',
    INDEX (bookID, status),
    FOREIGN KEY (studID) REFERENCES `Student`(studID),
    FOREIGN KEY (bookID) REFERENCES `Book`(bookID)
) ENGINE=InnoDB;

//index.php
*/
<?php
// A session lets us show a message after redirecting back to the page.
session_start();

// Ask MySQLi to report database errors as exceptions.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // 127.0.0.1 means MySQL is running on this computer.
    $conn = mysqli_connect('127.0.0.1', 'root', '', 'library_skills', 3306);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (mysqli_sql_exception $error) {
    exit('Connection failed. Start MySQL in XAMPP and check that library_skills exists.');
}

// Prepare and execute a query. Each ? takes one value from $values,
// in the same order. Keeping values separate also handles names like O'Connor.
function runQuery($conn, $sql, $values) {
    $stmt = mysqli_prepare($conn, $sql);

    // Make one "s" per value. For example, three values use "sss".
    $types = str_repeat('s', count($values));
    mysqli_stmt_bind_param($stmt, $types, ...$values);

    mysqli_stmt_execute($stmt);
    return $stmt;
}

// Use this for SELECT queries. Fetch the result before starting another query.
// This prevents the "Commands out of sync" error on the Books page.
function readQuery($conn, $sql, $values) {
    $stmt = runQuery($conn, $sql, $values);
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    return $result;
}

// Safely show database or form text inside HTML.
function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Count only copies that are still out. Returned copies are available again.
function countBorrowed($conn, $bookID) {
    $result = readQuery($conn,
        "SELECT COUNT(*) AS total
         FROM BorrowedBook
         WHERE bookID = ? AND status = 'Borrowed'",
        [$bookID]
    );

    $row = mysqli_fetch_assoc($result);
    return (int) $row['total'];
}

// Each entry gives the database table, primary key, page heading, and form fields.
// The left side of each field is the database column. The right side is its label.
$pages = [
    'authors' => ['Author', 'authID', 'Authors', [
        'authFName' => 'First Name',
        'authMName' => 'Middle Name',
        'authLName' => 'Last Name'
    ]],
    'books' => ['Book', 'bookID', 'Books', [
        'bookTitle' => 'Book Title',
        'bookCopies' => 'Total Copies',
        'authID' => 'Author'
    ]],
    'students' => ['Student', 'studID', 'Students', [
        'studFName' => 'First Name',
        'studMName' => 'Middle Name',
        'studLName' => 'Last Name'
    ]]
];

// GET reads the page name from links such as ?page=books.
$page = $_GET['page'] ?? 'authors';
if (!is_string($page) || (!isset($pages[$page]) && $page !== 'borrowing')) {
    $page = 'authors';
}

$list = ($_GET['list'] ?? '') === 'returned' ? 'returned' : 'borrowed';

// Read the previous action's message once, then clear it.
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

if (isset($pages[$page])) {
    [$table, $idColumn, $title, $fields] = $pages[$page];
    $columns = array_keys($fields);
}

// POST runs when a form submits Add, Update, Delete, Borrow, or Return.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $inTransaction = false;

    try {
        if (isset($pages[$page])) {
            // These table and column names come from the fixed $pages array.
            // The hidden ID is empty for Add and filled in for Edit.
            $id = (int) ($_POST['id'] ?? 0);

            if ($action === 'delete') {
                $stmt = runQuery($conn,
                    "DELETE FROM $table WHERE $idColumn = ?",
                    [$id]
                );

                $message = mysqli_stmt_affected_rows($stmt)
                    ? 'Record deleted.' : 'Record not found.';
            } elseif ($action === 'save') {
                // Read the form in the same order as the database columns.
                $values = [];
                foreach ($columns as $column) {
                    $value = trim($_POST[$column] ?? '');
                    $optional = $column === 'authMName' || $column === 'studMName';

                    if ($value === '' && !$optional) {
                        throw new RuntimeException('Fill in all required fields.');
                    }
                    $values[] = $value;
                }

                if ($page === 'books') {
                    $copies = filter_var($_POST['bookCopies'], FILTER_VALIDATE_INT, [
                        'options' => ['min_range' => 0, 'max_range' => 2147483647]
                    ]);
                    if ($copies === false) {
                        throw new RuntimeException('Enter a valid number of copies.');
                    }
                }

                if ($id > 0) {
                    // Update an existing record.
                    if ($page === 'books') {
                        // Lock this book until the update finishes, so a loan
                        // cannot take a copy while we are changing the total.
                        mysqli_begin_transaction($conn);
                        $inTransaction = true;

                        $result = readQuery($conn,
                            'SELECT bookID FROM Book WHERE bookID = ? FOR UPDATE',
                            [$id]
                        );
                        if (!mysqli_fetch_assoc($result)) {
                            throw new RuntimeException('Book not found.');
                        }
                        if ($copies < countBorrowed($conn, $id)) {
                            throw new RuntimeException('Total copies cannot be lower than the number already borrowed.');
                        }
                    }

                    // Turn the columns into "column1 = ?, column2 = ?, ...".
                    $assignments = implode(' = ?, ', $columns) . ' = ?';
                    $values[] = $id;
                    runQuery($conn,
                        "UPDATE $table SET $assignments WHERE $idColumn = ?",
                        $values
                    );

                    $message = 'Record updated.';
                } else {
                    // Build one ? per form field. The database generates the ID.
                    $names = implode(', ', $columns);
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                    runQuery($conn,
                        "INSERT INTO $table ($names) VALUES ($placeholders)",
                        $values
                    );

                    $message = 'Record added.';
                }
            }
        } elseif ($action === 'borrow') {
            $bookID = (int) ($_POST['bookID'] ?? 0);
            $studentID = (int) ($_POST['studID'] ?? 0);

            // Checking stock and adding the loan happen in one transaction.
            // The row lock keeps two requests from borrowing the last copy.
            mysqli_begin_transaction($conn);
            $inTransaction = true;

            $result = readQuery($conn,
                'SELECT bookCopies FROM Book WHERE bookID = ? FOR UPDATE',
                [$bookID]
            );
            $book = mysqli_fetch_assoc($result);

            if (!$book || countBorrowed($conn, $bookID) >= (int) $book['bookCopies']) {
                throw new RuntimeException('No copy is available for this book.');
            }

            runQuery($conn,
                "INSERT INTO BorrowedBook (studID, bookID, status)
                 VALUES (?, ?, 'Borrowed')",
                [$studentID, $bookID]
            );

            $message = 'Book borrowed.';
        } elseif ($action === 'return') {
            // borrowID identifies one loan, even if several students borrowed this book.
            $borrowID = (int) ($_POST['borrowID'] ?? 0);

            $stmt = runQuery($conn,
                "UPDATE BorrowedBook SET status = 'Returned'
                 WHERE borrowID = ? AND status = 'Borrowed'",
                [$borrowID]
            );

            $message = mysqli_stmt_affected_rows($stmt)
                ? 'Book returned.' : 'This loan is already returned or missing.';
        }

        // Commit saves the changes and releases the lock.
        if ($inTransaction) {
            mysqli_commit($conn);
        }
    } catch (mysqli_sql_exception $error) {
        // Rollback cancels unfinished changes and releases the lock.
        if ($inTransaction) {
            mysqli_rollback($conn);
        }

        if ($error->getCode() === 1451) {
            $message = 'This record is linked to another record and cannot be deleted.';
        } elseif ($error->getCode() === 1452) {
            $message = 'Select an existing author, student, or book.';
        } else {
            $message = 'MySQL error: ' . $error->getMessage();
        }
    } catch (RuntimeException $error) {
        if ($inTransaction) {
            mysqli_rollback($conn);
        }
        $message = $error->getMessage();
    }

    // Redirect so refreshing the browser does not submit the form again.
    $_SESSION['message'] = $message;
    header('Location: ?page=' . $page, true, 303);
    exit;
}

// GET also reads search words and the ID from an Edit link.
$search = trim($_GET['search'] ?? '');
$like = '%' . $search . '%';
$edit = [];

try {
    if (isset($pages[$page])) {
        $editID = (int) ($_GET['edit'] ?? 0);
        if ($editID > 0) {
            // Load the selected record so its values appear in the form.
            $result = readQuery($conn,
                "SELECT * FROM $table WHERE $idColumn = ?",
                [$editID]
            );
            $edit = mysqli_fetch_assoc($result) ?? [];
        }

        if ($page === 'books') {
            // JOIN matches the book's authID to its author's name.
            $records = readQuery($conn,
                "SELECT b.*, CONCAT(a.authFName, ' ', a.authLName) AS authorName
                 FROM Book b
                 JOIN Author a ON a.authID = b.authID
                 WHERE CONCAT_WS(' ', b.bookID, b.bookTitle, a.authFName, a.authLName) LIKE ?
                 ORDER BY b.bookTitle, b.bookID",
                [$like]
            );

            // Load the author dropdown after fetching the book list.
            $authors = mysqli_query($conn, 'SELECT * FROM Author ORDER BY authLName, authFName');
        } else {
            // Search by ID or name. An empty search shows all records.
            $nameFields = implode(', ', $columns);
            $records = readQuery($conn,
                "SELECT * FROM $table
                 WHERE CONCAT_WS(' ', $idColumn, $nameFields) LIKE ?
                 ORDER BY $idColumn",
                [$like]
            );
        }
    } else {
        // Available copies = total copies minus loans still marked Borrowed.
        $result = readQuery($conn,
            "SELECT b.*, b.bookCopies - (
                SELECT COUNT(*) FROM BorrowedBook l
                WHERE l.bookID = b.bookID AND l.status = 'Borrowed'
             ) AS available
             FROM Book b
             WHERE CONCAT_WS(' ', b.bookID, b.bookTitle) LIKE ?
             ORDER BY b.bookTitle, b.bookID",
            [$like]
        );

        // Save the rows in an array because the table and dropdown both use them.
        $books = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $students = mysqli_query($conn, 'SELECT * FROM Student ORDER BY studLName, studFName');

        // This filter keeps borrowed and returned records in separate lists.
        $status = $list === 'returned' ? 'Returned' : 'Borrowed';
        $loans = readQuery($conn,
            "SELECT l.borrowID, b.bookTitle, s.studID, s.studFName, s.studLName
             FROM BorrowedBook l
             JOIN Book b ON b.bookID = l.bookID
             JOIN Student s ON s.studID = l.studID
             WHERE l.status = ?
             ORDER BY l.borrowID DESC",
            [$status]
        );
    }
} catch (mysqli_sql_exception $error) {
    exit('MySQL error: ' . h($error->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BookBorrower</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 24px auto; padding: 0 12px; }
        nav a { display: inline-block; margin: 0 14px 8px 0; }
        form { margin: 12px 0; }
        label { display: block; margin: 8px 0; }
        input, select, button { padding: 5px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
        th, td { border: 1px solid #aaa; padding: 7px; text-align: left; }
        th { background: #eee; }
        td form { display: inline; }
    </style>
</head>
<body>

<h1>BookBorrower</h1>

<nav>
    <a href="?page=authors">Authors</a>
    <a href="?page=books">Books</a>
    <a href="?page=students">Students</a>
    <a href="?page=borrowing">Borrowing</a>
</nav>

<?php if ($message !== '') { ?>
    <p role="status"><?= h($message) ?></p>
<?php } ?>

<?php if (isset($pages[$page])) { ?>

    <h2><?= h($title) ?></h2>

    <!-- The same form adds a new record or updates the selected record. -->
    <form method="post" action="?page=<?= h($page) ?>">
        <input type="hidden" name="id" value="<?= h($edit[$idColumn] ?? '') ?>">

        <?php foreach ($fields as $column => $label) { ?>
            <label>
                <?= h($label) ?>

                <?php if ($column === 'authID') { ?>
                    <!-- Display the author's name, but submit the author's ID. -->
                    <select name="authID" required>
                        <option value="">Select author</option>
                        <?php while ($author = mysqli_fetch_assoc($authors)) { ?>
                            <option value="<?= h($author['authID']) ?>"
                                <?= ($edit['authID'] ?? '') == $author['authID'] ? 'selected' : '' ?>>
                                <?= h($author['authLName'] . ', ' . $author['authFName']) ?>
                            </option>
                        <?php } ?>
                    </select>
                <?php } elseif ($column === 'bookCopies') { ?>
                    <input type="number" name="bookCopies" min="0" max="2147483647"
                           value="<?= h($edit[$column] ?? '') ?>" required>
                <?php } else { ?>
                    <input name="<?= h($column) ?>" value="<?= h($edit[$column] ?? '') ?>"
                           maxlength="<?= $column === 'bookTitle' ? 200 : 100 ?>"
                           <?= $column === 'authMName' || $column === 'studMName' ? '' : 'required' ?>>
                <?php } ?>
            </label>
        <?php } ?>

        <button name="action" value="save"><?= $edit ? 'Update' : 'Add' ?></button>
        <a href="?page=<?= h($page) ?>">Clear</a>
    </form>

    <!-- Search uses GET because it only reads records. -->
    <form method="get">
        <input type="hidden" name="page" value="<?= h($page) ?>">
        <label>Search <input name="search" value="<?= h($search) ?>"></label>
        <button>Search</button>
        <a href="?page=<?= h($page) ?>">Show all</a>
    </form>

    <table>
        <tr>
            <th>ID</th>
            <?php foreach ($fields as $label) { ?>
                <th><?= h($label) ?></th>
            <?php } ?>
            <th>Actions</th>
        </tr>

        <?php while ($row = mysqli_fetch_assoc($records)) { ?>
            <tr>
                <td><?= h($row[$idColumn]) ?></td>
                <?php foreach ($columns as $column) { ?>
                    <td><?= h($column === 'authID' ? $row['authorName'] : $row[$column]) ?></td>
                <?php } ?>
                <td>
                    <a href="?page=<?= h($page) ?>&amp;edit=<?= h($row[$idColumn]) ?>">Edit</a>
                    <form method="post" action="?page=<?= h($page) ?>"
                          onsubmit="return confirm('Delete this record?')">
                        <input type="hidden" name="id" value="<?= h($row[$idColumn]) ?>">
                        <button name="action" value="delete">Delete</button>
                    </form>
                </td>
            </tr>
        <?php } ?>

        <?php if (mysqli_num_rows($records) === 0) { ?>
            <tr><td colspan="<?= count($columns) + 2 ?>">No records found.</td></tr>
        <?php } ?>
    </table>

<?php } else { ?>

    <h2>Book Borrowing</h2>

    <form method="get">
        <input type="hidden" name="page" value="borrowing">
        <input type="hidden" name="list" value="<?= h($list) ?>">
        <label>Find book <input name="search" value="<?= h($search) ?>"></label>
        <button>Check Availability</button>
        <a href="?page=borrowing&amp;list=<?= h($list) ?>">Show all books</a>
    </form>

    <table>
        <tr><th>ID</th><th>Book</th><th>Total Copies</th><th>Available</th></tr>
        <?php foreach ($books as $book) { ?>
            <tr>
                <td><?= h($book['bookID']) ?></td>
                <td><?= h($book['bookTitle']) ?></td>
                <td><?= h($book['bookCopies']) ?></td>
                <td><?= h($book['available']) ?></td>
            </tr>
        <?php } ?>
        <?php if (!$books) { ?>
            <tr><td colspan="4">No books found.</td></tr>
        <?php } ?>
    </table>

    <form method="post" action="?page=borrowing">
        <label>
            Student
            <select name="studID" required>
                <option value="">Select student</option>
                <?php while ($student = mysqli_fetch_assoc($students)) { ?>
                    <option value="<?= h($student['studID']) ?>">
                        <?= h($student['studID'] . ' - ' . $student['studFName'] . ' ' . $student['studLName']) ?>
                    </option>
                <?php } ?>
            </select>
        </label>

        <label>
            Book
            <select name="bookID" required>
                <option value="">Select available book</option>
                <!-- Show only books with at least one available copy. -->
                <?php foreach ($books as $book) { ?>
                    <?php if ($book['available'] > 0) { ?>
                        <option value="<?= h($book['bookID']) ?>">
                            <?= h($book['bookID'] . ' - ' . $book['bookTitle']) ?>
                        </option>
                    <?php } ?>
                <?php } ?>
            </select>
        </label>

        <button name="action" value="borrow">Borrow Book</button>
    </form>

    <h3><?= h($status) ?> Books</h3>
    <nav>
        <a href="?page=borrowing&amp;list=borrowed">Borrowed Only</a>
        <a href="?page=borrowing&amp;list=returned">Returned Only</a>
    </nav>

    <table>
        <tr>
            <th>Borrow ID</th><th>Book</th><th>Student</th>
            <?php if ($list === 'borrowed') { ?><th>Action</th><?php } ?>
        </tr>

        <?php while ($loan = mysqli_fetch_assoc($loans)) { ?>
            <tr>
                <td><?= h($loan['borrowID']) ?></td>
                <td><?= h($loan['bookTitle']) ?></td>
                <td><?= h($loan['studID'] . ' - ' . $loan['studFName'] . ' ' . $loan['studLName']) ?></td>
                <?php if ($list === 'borrowed') { ?>
                    <td>
                        <form method="post" action="?page=borrowing">
                            <input type="hidden" name="borrowID" value="<?= h($loan['borrowID']) ?>">
                            <button name="action" value="return">Return</button>
                        </form>
                    </td>
                <?php } ?>
            </tr>
        <?php } ?>

        <?php if (mysqli_num_rows($loans) === 0) { ?>
            <tr><td colspan="<?= $list === 'borrowed' ? 4 : 3 ?>">No records found.</td></tr>
        <?php } ?>
    </table>

<?php } ?>

</body>
</html>