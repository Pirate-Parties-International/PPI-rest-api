(function($) {
    var app = angular.module('app', ['infinite-scroll']); 
    //directive that cheks if an image is loaded
    /*app.directive('imageonload', function() {
        return {
            restrict: 'A',
            link: function(scope, element, attrs) {
                element.bind('load', function() {
                    return true;
                });
                element.bind('error', function(){
                    return false;
                });
            }
        };
    });*/

    app.controller('pictureController', ['$scope', '$timeout', function($scope, $timeout) {
        $scope.data =[];
        $scope.tempData =[]
        //temporary function that creates fake data in the same format as I expect to get the data though an API
        var getFakeData = function(){
            var data = {};           
            for (var i = 0; i < 1000; i++){
                data[i] = { url: "http://loremflickr.com/200/200?random="+i};
                if ((i%2)==0){
                    data[i]["socialPlatform"] = "FB"
                    data[i]["likes"] = 10000 + (i)*2
                }
                else {
                    data[i]["socialPlatform"] = "TW"
                    data[i]["likes"] = 10000 + (-i)*2
                };
            };
            return data;
    	};
        //this functions loads more data for the infinite scroll
        // it constantily updates the array from which ng-rpeat gets its data
        $scope.loadMore = function() {
            var last = [];
            var x
            if ( $scope.data.length == 0){
                x = 0
            }
            else {
                x = $scope.data.length - 1;
            }
            for(var i = 1; i <= 20; i++) {
                var currentValue = $scope.tempData[x+i];        
                $scope.data.push(currentValue);
            }
        };

        //Function that runs after data is acquired
        //it transforms the object into an array and runs 
        $.when(getFakeData()).done(function(data){ 
            for (var x in data){
                $scope.tempData.push(data[x]); 
            };
            console.log($scope.tempData);
            $scope.loadMore(); 

        });


    }]);
})(jQuery); // end of jQuery name space