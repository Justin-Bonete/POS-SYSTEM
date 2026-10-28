<?php
$pageTitle = 'Categories';
session_start();
require_once '../../includes/auth.php';
requireAuth();
require_once '../../config/database.php';
require_once '../../classes/Category.php';

$category = new Category();
$categories = $category->getAll();
$stats = $category->getStatistics();
?>
<?php include '../layouts/header.php'; ?>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h3>Add New Category</h3>
            </div>
            <div class="card-body">
                <form id="addCategoryForm">
                    <div class="form-group">
                        <label class="form-label">Category Name *</label>
                        <input type="text" id="categoryName" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea id="categoryDescription" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3>Categories List</h3>
            </div>
            <div class="card-body">
                <!-- Statistics -->
                <div class="stats-row mb-3">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3>Total Categories</h3>
                            <div class="stat-number"><?php echo $stats['total_categories'] ?? 0; ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-tags"></i>
                        </div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-info">
                            <h3>Active Categories</h3>
                            <div class="stat-number"><?php echo $stats['active_categories'] ?? 0; ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-info">
                            <h3>Categories with Products</h3>
                            <div class="stat-number"><?php echo $stats['categories_with_products'] ?? 0; ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-box"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Categories Table -->
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?php echo $cat['id']; ?></td>
                            <td><?php echo htmlspecialchars($cat['name']); ?></td>
                            <td><?php echo htmlspecialchars($cat['description'] ?? '-'); ?></td>
                            <td><?php echo $cat['product_count'] ?? 0; ?></td>
                            <td>
                                <span class="badge badge-<?php echo $cat['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo $cat['status']; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($cat['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editCategory(<?php echo $cat['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteCategory(<?php echo $cat['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal" id="editCategoryModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Category</h5>
                <button type="button" class="modal-close" onclick="closeModal('editCategoryModal')">&times;</button>
            </div>
            <form id="editCategoryForm">
                <div class="modal-body">
                    <input type="hidden" id="edit-category-id" name="id">
                    
                    <div class="form-group">
                        <label class="form-label">Category Name</label>
                        <input type="text" id="edit-category-name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea id="edit-category-description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select id="edit-category-status" name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editCategoryModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('addCategoryForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const data = {
        name: document.getElementById('categoryName').value,
        description: document.getElementById('categoryDescription').value
    };
    
    try {
        const response = await fetch('/pos-system/api/categories/add.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Category added successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(result.message || 'Failed to add category', 'error');
        }
    } catch (error) {
        showNotification('Error: ' + error.message, 'error');
    }
});

async function editCategory(id) {
    try {
        // Fetch category details
        const response = await fetch(`/pos-system/api/categories/get.php?id=${id}`);
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('edit-category-id').value = result.data.id;
            document.getElementById('edit-category-name').value = result.data.name;
            document.getElementById('edit-category-description').value = result.data.description || '';
            document.getElementById('edit-category-status').value = result.data.status;
            
            openModal('editCategoryModal');
        }
    } catch (error) {
        showNotification('Failed to load category', 'error');
    }
}

document.getElementById('editCategoryForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const data = {
        id: document.getElementById('edit-category-id').value,
        name: document.getElementById('edit-category-name').value,
        description: document.getElementById('edit-category-description').value,
        status: document.getElementById('edit-category-status').value
    };
    
    try {
        const response = await fetch('/pos-system/api/categories/update.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Category updated successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(result.message || 'Failed to update category', 'error');
        }
    } catch (error) {
        showNotification('Error: ' + error.message, 'error');
    }
});

async function deleteCategory(id) {
    confirmAction('This category will be deleted. Continue?', async () => {
        try {
            const response = await fetch('/pos-system/api/categories/delete.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showNotification('Category deleted successfully!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification(result.message || 'Failed to delete category', 'error');
            }
        } catch (error) {
            showNotification('Error: ' + error.message, 'error');
        }
    });
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}
</script>

<?php include '../layouts/footer.php'; ?>