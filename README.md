# 🚀 Laravel Form Builder Package

**Laravel Form Builder** is a package that allows developers to create dynamic APIs without writing manual queries in the backend. With this package, the frontend can retrieve data from the database without requiring a new API for each request.

---

## 📦 Features
✅ **CRUD operations** for Data Sources  
✅ **Dynamic queries** based on tables, columns, and parameters  
✅ Supports **custom queries** (only `SELECT` queries allowed)  
✅ **Caching** for improved query performance  
✅ API to get **list of tables** and **list of columns** from the database  
✅ Easy installation as a **Laravel Package**  

---

## 🛠 Installation

### **1️⃣ Add the Package to Laravel**

```bash
composer require elgibor-solution/laravel-form-builder
```

---

### **2️⃣ Publish and Run Migrations**
```bash
php artisan migrate
```

To publish migration files to your Laravel project:
```bash
php artisan vendor:publish --provider="ESolution\DataSources\Providers\DataSourcesServiceProvider" --tag=migrations
```

---

## 📌 API Endpoints

### **1️⃣ Get All Data Sources**
```http
GET /api/data-sources
```

### **2️⃣ Create a New Data Source**
```http
POST /api/data-sources
Content-Type: application/json
```
**Body:**
```json
{
    "name": "Users List",
    "table_name": "users",
    "use_custom_query": false,
    "columns": ["id", "name", "email"]
}
```

### **3️⃣ Execute Query from a Data Source**
```http
GET /api/data-sources/{id}/query
```

### **4️⃣ Get All Tables in the Database**
```http
GET /api/data-sources/tables
```

### **5️⃣ Get All Columns from a Table**
```http
GET /api/data-sources/tables/{table}/columns
```

---

## ⚡ Technologies Used
- **Laravel 8+**
- **MySQL / PostgreSQL**
- **Redis Cache**

---

## 🤝 Contributing
If you’d like to contribute to this project, feel free to fork the repository and submit a pull request! 🚀

---
✌ **Happy Coding!**
