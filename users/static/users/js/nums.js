//var express = require('express');
//var mongodb = require('mongodb');
//var MongoDataTable = require('mongo-datatable');
//var MongoClient = mongodb.MongoClient;
//var router = express.Router();
//
//router.get('/data.json', function(req, res, next) {
//  var options = req.query;
//  options.showAlertOnError = true;
//
//  /**
//   * Using customQuery for specific needs such as
//   * filtering data which has `role` property set to user
//   */
//  options.customQuery = {
//    role: 'user'
//  };
//
//  /* uncomment the line below to enable case insensitive search */
//  // options.caseInsensitiveSearch = true;
//
//  MongoClient.connect('mongodb://localhost/database', function(err, db) {
//    new MongoDataTable(db).get('collection', options, function(err, result) {
//      if (err) {
//        // handle the error
//      }
//
//      res.json(result);
//    });
//  });
//});




var express = require('express');
var mongodb = require('mongodb');
var MongoDataTable = require('mongo-datatable');
var Db = mongodb.Db;
var Server = mongodb.Server;
var router = express.Router();

router.get('/data.json', function(req, res, next) {
  var options = req.query;
  var db = new Db('BeauEnslow', new Server('mongodb+srv://benslow:Grannyboy1@cluster1.9f0gx.mongodb.net', 27017));

  options.showAlertOnError = true;

  /**
   * Using customQuery for specific needs such as
   * filtering data which has `role` property set to user
   */
  options.customQuery = {
    role: 'user'
  };

  // uncomment the line below to enable case insensitive search
  // options.caseInsensitiveSearch = true;

  db.open(function(error, db) {
    new MongoDataTable(db).get('LEADS', options, function(err, result) {
      if (err) {
        // handle the error
      }

      res.json(result);
    });
  });
});