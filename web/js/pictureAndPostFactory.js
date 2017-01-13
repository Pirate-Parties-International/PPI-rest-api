angular.module("app").factory("pictureAndPostFactory", function($http)
{
    var pictureAndPostFactory = {
        partyList : function(){
            var promise = $http.get('http://api.piratetimes.net/api/v1/parties/?_format=json&show_defunct=false')
            .then(function(response) {
                //First function handles success
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring party list")
        });  
            return promise
        }, 
        imageList : function(x){
            var promise = $http.get(x)
            .then(function(response) {
                //First function handles success
                return response.data;
            }, function(response) {
                //Second function handles error
                console.log("error in acquring image list")
        });  
            return promise
        }
    };
return pictureAndPostFactory;
});