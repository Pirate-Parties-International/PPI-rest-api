angular.module("app").factory("pictureAndPostFactory", function($http) {
    var address = "/api/v1/"
    var pictureAndPostFactory = {
        partyList: function() {
            var promise = $http.get(address + "parties/?_format=json")
                .then(function(response) {
                    //First function handles success
                    return response.data;
                }, function(response) {
                    //Second function handles error
                    console.log("error in acquring party list")
                });
            return promise
        },
        postList: function(x) {
            var promise = $http.get(address + "social/?_format=json&sub_type=T&order_by=" + x.sort + "&direction=" + x.direction + "&code=" + x.partyCode + "&type=" + x.socialPlatform + "&offset=" + x.offset + "&recent=" + x.recent)
                .then(function(response) {
                    //First function handles success
                    return response.data;
                }, function(response) {
                    //Second function handles error
                    console.log("error in acquring list")
                });
            return promise
        },
        pictureList: function(x) {
            var promise = $http.get(address + "social/?_format=json&sub_type=I&order_by=" + x.sort + "&direction=" + x.direction + "&code=" + x.partyCode + "&type=" + x.socialPlatform + "&offset=" + x.offset + "&recent=" + x.recent)
                .then(function(response) {
                    //First function handles success
                    return response.data;
                }, function(response) {
                    //Second function handles error
                    console.log("error in acquring list")
                });
            return promise
        },
    };
    return pictureAndPostFactory;
});