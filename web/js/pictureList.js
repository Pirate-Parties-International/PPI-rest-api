(function($) {
    var app = angular.module('app', ['infinite-scroll']); 

       
    app.controller('pictureController', ['$scope', '$timeout', function($scope, $timeout) {
        $scope.greeting = 'Hola!';
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
        $scope.loadMore = function() {
            var last = $scope.data[$scope.data.length - 1];
            for(var i = 1; i <= 20; i++) {
              $scope.data.push($scope.tempData[last + i]);
            }
            $scope.fireEvent()
        };
        // This will only run after the ng-repeat has rendered its things to the DOM
        // there is a slighly timeout, because ng-repeat doesn't render fast enough
        //this function wraps every 4 images into its own row
        $scope.fireEvent = function(){
            $timeout(function(){
                var classes = $(".s3.placement-class");
                console.log(classes)
                for(var i = 0; i < classes.length; i+=4) {
                classes.slice(i, i+4).wrapAll("<div class='row'></div>");
                }
            }, 50)
        };  

        //Function that runs after data is acquired
        $.when(getFakeData()).done(function(data){ 
            $scope.tempData =[];
            $scope.data =[];
            for (var x in data){
                $scope.tempData.push(data[x]);
            };
            $scope.loadMore();   
        });


    }]);
})(jQuery); // end of jQuery name space